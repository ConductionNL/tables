<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Model;

use InvalidArgumentException;
use OCA\Tables\Constants\FilterOperator;
use OCA\Tables\Model\RowListQuery;
use PHPUnit\Framework\TestCase;

class RowListQueryTest extends TestCase {
	private function query(
		?string $page = null,
		?string $offset = null,
		?string $limit = null,
		?string $sort = null,
		?string $search = null,
		?string $fields = null,
		mixed $filter = null,
	): RowListQuery {
		return RowListQuery::createFromRequest($page, $offset, $limit, $sort, $search, $fields, $filter);
	}

	public function testDefaults(): void {
		$query = $this->query();

		$this->assertSame(25, $query->limit);
		$this->assertSame(0, $query->offset);
		$this->assertSame(1, $query->getPage());
		$this->assertSame([], $query->sort);
		$this->assertNull($query->search);
		$this->assertNull($query->fields);
		$this->assertSame([], $query->filters);
	}

	public function testPageIsConvertedToAnOffset(): void {
		$query = $this->query(page: '3', limit: '10');

		$this->assertSame(20, $query->offset);
		$this->assertSame(3, $query->getPage());
	}

	public function testOffsetReportsThePageOfItsFirstRow(): void {
		$query = $this->query(offset: '30', limit: '25');

		$this->assertSame(30, $query->offset);
		$this->assertSame(2, $query->getPage());
	}

	public static function invalidPagingProvider(): array {
		return [
			'page 0' => ['0', null, null],
			'negative page' => ['-1', null, null],
			'page not a number' => ['abc', null, null],
			'page with trailing text' => ['2abc', null, null],
			'limit 0' => [null, null, '0'],
			'negative limit' => [null, null, '-5'],
			'limit not a number' => [null, null, 'all'],
			'negative offset' => [null, '-1', null],
			'page and offset' => ['1', '0', null],
		];
	}

	/**
	 * @dataProvider invalidPagingProvider
	 */
	public function testInvalidPagingIsRejected(?string $page, ?string $offset, ?string $limit): void {
		$this->expectException(InvalidArgumentException::class);
		$this->query(page: $page, offset: $offset, limit: $limit);
	}

	public function testSortIsParsedWithDirections(): void {
		$query = $this->query(sort: '-createdAt, name');

		$this->assertSame([
			['field' => 'createdAt', 'mode' => 'DESC'],
			['field' => 'name', 'mode' => 'ASC'],
		], $query->sort);
	}

	public function testSortWithAnEmptyEntryIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->query(sort: 'name,,age');
	}

	public function testSortWithOnlyAMinusIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->query(sort: '-');
	}

	public function testFieldsAreParsed(): void {
		$this->assertSame(['name', '12'], $this->query(fields: 'name, 12')->fields);
	}

	public function testABlankSearchIsIgnored(): void {
		$this->assertNull($this->query(search: '   ')->search);
		$this->assertSame('jane', $this->query(search: ' jane ')->search);
	}

	public function testAFilterWithoutOperator(): void {
		$query = $this->query(filter: ['name' => 'jane']);

		$this->assertSame([['field' => 'name', 'operator' => null, 'value' => 'jane']], $query->filters);
	}

	public function testFiltersWithOperators(): void {
		$query = $this->query(filter: ['age' => ['is-greater-than' => '20', 'is-lower-than' => '30'], '12' => 'x']);

		$this->assertSame([
			['field' => 'age', 'operator' => FilterOperator::IS_GREATER_THAN, 'value' => '20'],
			['field' => 'age', 'operator' => FilterOperator::IS_LESS_THAN, 'value' => '30'],
			['field' => '12', 'operator' => null, 'value' => 'x'],
		], $query->filters);
	}

	public static function invalidFilterProvider(): array {
		return [
			'not an array' => ['name=jane'],
			'unknown operator' => [['name' => ['like' => 'jane']]],
			'nested value' => [['name' => ['is-equal' => ['jane']]]],
			'no value' => [['name' => []]],
		];
	}

	/**
	 * @dataProvider invalidFilterProvider
	 */
	public function testInvalidFiltersAreRejected(mixed $filter): void {
		$this->expectException(InvalidArgumentException::class);
		$this->query(filter: $filter);
	}
}
