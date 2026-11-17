<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Db\Column;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Model\RowListQuery;
use OCA\Tables\Service\RowQueryResolver;
use PHPUnit\Framework\TestCase;

class RowQueryResolverTest extends TestCase {
	private RowQueryResolver $resolver;
	/** @var Column[] */
	private array $columns;

	protected function setUp(): void {
		$this->resolver = new RowQueryResolver();
		$this->columns = [
			$this->column(1, 'name', Column::TYPE_TEXT),
			$this->column(2, 'age', Column::TYPE_NUMBER),
			$this->column(3, 'status', Column::TYPE_SELECTION, '', [['id' => 0, 'label' => 'Active'], ['id' => 1, 'label' => 'Inactive']]),
			$this->column(4, 'tags', Column::TYPE_SELECTION, 'multi', [['id' => 5, 'label' => 'Urgent'], ['id' => 6, 'label' => 'Later']]),
			$this->column(5, 'owner', Column::TYPE_USERGROUP),
			$this->column(6, 'done', Column::TYPE_SELECTION, Column::SUBTYPE_SELECTION_CHECK),
		];
	}

	private function column(int $id, string $technicalName, string $type, string $subtype = '', ?array $options = null): Column {
		$column = new Column();
		$column->setId($id);
		$column->setTechnicalName($technicalName);
		$column->setType($type);
		$column->setSubtype($subtype);
		if ($options !== null) {
			$column->setSelectionOptions(json_encode($options));
		}
		return $column;
	}

	private function resolve(array $parameters, array $viewFilter = [], array $defaultSort = [], ?array $columns = null): array {
		$query = RowListQuery::createFromRequest(
			$parameters['page'] ?? null,
			$parameters['offset'] ?? null,
			$parameters['limit'] ?? null,
			$parameters['sort'] ?? null,
			$parameters['search'] ?? null,
			$parameters['fields'] ?? null,
			$parameters['filter'] ?? null,
		);
		return $this->resolver->resolve($query, $columns ?? $this->columns, $viewFilter, $defaultSort);
	}

	public function testWithoutParametersEverythingVisibleIsReturned(): void {
		$criteria = $this->resolve([], [], [['columnId' => 2, 'mode' => 'ASC']]);

		$this->assertSame([1, 2, 3, 4, 5, 6], $criteria['columnIds']);
		$this->assertSame([], $criteria['filter']);
		$this->assertSame([['columnId' => 2, 'mode' => 'ASC']], $criteria['sort']);
		$this->assertFalse($criteria['matchesNothing']);
	}

	public function testFieldsByTechnicalNameAndIdKeepTheColumnOrder(): void {
		$criteria = $this->resolve(['fields' => 'age,1']);

		$this->assertSame([1, 2], $criteria['columnIds']);
	}

	public function testMetaFieldsAreAcceptedButNotLoadedAsColumns(): void {
		$this->assertSame([], $this->resolve(['fields' => 'id,createdAt'])['columnIds']);
	}

	public function testAnUnknownFieldIsRejected(): void {
		$this->expectException(BadRequestError::class);
		$this->resolve(['fields' => 'salary']);
	}

	public function testAColumnOutsideTheVisibleColumnsIsRejected(): void {
		$this->expectException(BadRequestError::class);
		$this->resolve(['filter' => ['99' => 'x']]);
	}

	public function testUuidIsReserved(): void {
		$this->expectException(BadRequestError::class);
		$this->resolve(['sort' => 'uuid']);
	}

	public function testSortReplacesTheDefaultSort(): void {
		$criteria = $this->resolve(['sort' => '-updatedAt,name'], [], [['columnId' => 2, 'mode' => 'ASC']]);

		$this->assertSame([
			['columnId' => Column::TYPE_META_UPDATED_AT, 'mode' => 'DESC'],
			['columnId' => 1, 'mode' => 'ASC'],
		], $criteria['sort']);
	}

	public function testAFilterWithoutOperatorChecksEquality(): void {
		$criteria = $this->resolve(['filter' => ['name' => 'jane']]);

		$this->assertSame([[['columnId' => 1, 'operator' => 'is-equal', 'value' => 'jane']]], $criteria['filter']);
	}

	public function testAFilterWithoutOperatorOnAMultiValueColumnChecksForTheItem(): void {
		$criteria = $this->resolve(['filter' => ['tags' => '5', 'owner' => 'jane']]);

		$this->assertSame([[
			['columnId' => 4, 'operator' => 'contains-item', 'value' => '5'],
			['columnId' => 5, 'operator' => 'contains-item', 'value' => 'jane'],
		]], $criteria['filter']);
	}

	public function testRequestFiltersAreAddedToEveryGroupOfTheViewFilter(): void {
		$viewFilter = [
			[['columnId' => 2, 'operator' => 'is-greater-than', 'value' => '18']],
			[['columnId' => 3, 'operator' => 'is-equal', 'value' => '0']],
		];

		$criteria = $this->resolve(['filter' => ['name' => ['begins-with' => 'j']]], $viewFilter);

		$requestFilter = ['columnId' => 1, 'operator' => 'begins-with', 'value' => 'j'];
		$this->assertSame([
			[$viewFilter[0][0], $requestFilter],
			[$viewFilter[1][0], $requestFilter],
		], $criteria['filter']);
	}

	public function testMetaFieldsCanBeFiltered(): void {
		$criteria = $this->resolve(['filter' => ['createdBy' => 'jane', 'createdAt' => ['is-greater-than' => '2026-01-01']]]);

		$this->assertSame([[
			['columnId' => Column::TYPE_META_CREATED_BY, 'operator' => 'is-equal', 'value' => 'jane'],
			['columnId' => Column::TYPE_META_CREATED_AT, 'operator' => 'is-greater-than', 'value' => '2026-01-01'],
		]], $criteria['filter']);
	}

	public function testAMetaDateFilterNeedsADate(): void {
		$this->expectException(BadRequestError::class);
		$this->resolve(['filter' => ['createdAt' => 'yesterday-ish']]);
	}

	public function testTheIdFilterNeedsAnInteger(): void {
		$this->expectException(BadRequestError::class);
		$this->resolve(['filter' => ['id' => 'abc']]);
	}

	public function testMetaFieldsDoNotSupportContainsItem(): void {
		$this->expectException(BadRequestError::class);
		$this->resolve(['filter' => ['createdBy' => ['contains-item' => 'jane']]]);
	}

	public function testAMetaFieldNameWinsOverATechnicalName(): void {
		$columns = [$this->column(7, 'id', Column::TYPE_TEXT)];

		$criteria = $this->resolve(['filter' => ['id' => '3', '7' => 'x']], [], [], $columns);

		$this->assertSame([[
			['columnId' => Column::TYPE_META_ID, 'operator' => 'is-equal', 'value' => '3'],
			['columnId' => 7, 'operator' => 'is-equal', 'value' => 'x'],
		]], $criteria['filter']);
	}

	public function testSearchMatchesTextCellsAndSelectionLabels(): void {
		$criteria = $this->resolve(['search' => 'act']);

		$this->assertSame([
			[['columnId' => 1, 'operator' => 'contains', 'value' => 'act']],
			[['columnId' => 3, 'operator' => 'is-equal', 'value' => '0']],
			[['columnId' => 3, 'operator' => 'is-equal', 'value' => '1']],
		], $criteria['filter']);
	}

	public function testSearchOnAMultiSelectionMatchesTheItem(): void {
		$criteria = $this->resolve(['search' => 'urg', 'fields' => 'tags'], [], [], [$this->columns[3]]);

		$this->assertSame([[['columnId' => 4, 'operator' => 'contains', 'value' => '5']]], $criteria['filter']);
	}

	public function testSearchIsCombinedWithTheViewFilterAndTheRequestFilters(): void {
		$viewFilter = [[['columnId' => 2, 'operator' => 'is-greater-than', 'value' => '18']]];

		$criteria = $this->resolve(['search' => 'later', 'filter' => ['name' => 'jane']], $viewFilter);

		$this->assertSame([[
			['columnId' => 2, 'operator' => 'is-greater-than', 'value' => '18'],
			['columnId' => 1, 'operator' => 'is-equal', 'value' => 'jane'],
			['columnId' => 1, 'operator' => 'contains', 'value' => 'later'],
		], [
			['columnId' => 2, 'operator' => 'is-greater-than', 'value' => '18'],
			['columnId' => 1, 'operator' => 'is-equal', 'value' => 'jane'],
			['columnId' => 4, 'operator' => 'contains', 'value' => '6'],
		]], $criteria['filter']);
	}

	public function testASearchNoColumnCanMatchMatchesNothing(): void {
		$criteria = $this->resolve(['search' => 'anything'], [], [], [$this->columns[1]]);

		$this->assertTrue($criteria['matchesNothing']);
	}
}
