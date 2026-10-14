<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Activity\ActivityManager;
use OCA\Tables\Db\Column;
use OCA\Tables\Db\Table;
use OCA\Tables\Db\TableMapper;
use OCA\Tables\Db\View;
use OCA\Tables\Db\ViewMapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Model\RowListQuery;
use OCA\Tables\Notification\NotificationHelper;
use OCA\Tables\Service\PermissionsService;
use OCA\Tables\Service\RowQueryResolver;
use OCA\Tables\Service\RowService;
use OCA\Tables\Tests\Unit\Database\DatabaseTestCase;
use OCA\Tables\Tests\Unit\Db\Row2MapperTestDependencies;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Lists the five people of the shared Row2Mapper test table through RowService::findPage.
 */
class RowServiceFindPageTest extends DatabaseTestCase {
	use Row2MapperTestDependencies;

	private const COLUMNS = ['name', 'age', 'birthday', 'department', 'score', 'surname', 'status', 'skills', 'is_available', 'experience_years'];
	private const VIEW_ID = 1;

	private RowService $rowService;
	private ViewMapper&MockObject $viewMapper;
	/** @var array<string, Column> */
	private array $columnsByName = [];

	protected function setUp(): void {
		parent::setUp();
		$this->setupDependencies();
		$this->setupRealColumnMapper(self::$testTableId);

		foreach (self::COLUMNS as $index => $technicalName) {
			$column = $this->columnMapper->find(self::$testColumnIds[$index]);
			$column->setTechnicalName($technicalName);
			$this->columnsByName[$technicalName] = $column;
		}
		$this->columnsByName['status']->setSelectionOptions(json_encode([['id' => 0, 'label' => 'Active'], ['id' => 1, 'label' => 'Inactive'], ['id' => 2, 'label' => 'Pending']]));
		$columns = array_values($this->columnsByName);
		$this->columnMapper->method('findAllByTable')->willReturn($columns);
		$this->columnMapper->method('findAll')->willReturnCallback(
			fn (array $columnIds): array => array_values(array_filter($columns, static fn (Column $column): bool => in_array($column->getId(), $columnIds, true))),
		);

		$permissionsService = $this->createMock(PermissionsService::class);
		$permissionsService->method('canReadRowsByElementId')->willReturn(true);
		$tableMapper = $this->createMock(TableMapper::class);
		$tableMapper->method('find')->willReturn(new Table());
		$this->viewMapper = $this->createMock(ViewMapper::class);

		$this->rowService = new RowService(
			$this->logger,
			'test_user',
			$permissionsService,
			$this->columnMapper,
			$this->viewMapper,
			$tableMapper,
			$this->mapper,
			$this->createMock(IEventDispatcher::class),
			$this->columnsHelper,
			$this->createMock(ActivityManager::class),
			$this->createMock(NotificationHelper::class),
			$this->createMock(IDBConnection::class),
			new RowQueryResolver(),
		);
	}

	/**
	 * @return array{names: list<string>, total: int}
	 */
	private function listTable(array $parameters): array {
		return $this->namesOf($this->rowService->findPage($this->query($parameters), self::$testTableId, null, 'test_user'));
	}

	private function query(array $parameters): RowListQuery {
		return RowListQuery::createFromRequest(
			$parameters['page'] ?? null,
			$parameters['offset'] ?? null,
			$parameters['limit'] ?? null,
			$parameters['sort'] ?? null,
			$parameters['search'] ?? null,
			$parameters['fields'] ?? null,
			$parameters['filter'] ?? null,
		);
	}

	/**
	 * @return array{names: list<string>, total: int}
	 */
	private function namesOf(array $found): array {
		$nameColumnId = $this->columnsByName['name']->getId();
		$names = [];
		foreach ($found['rows'] as $row) {
			foreach ($row->getData() as $cell) {
				if ($cell['columnId'] === $nameColumnId) {
					$names[] = $cell['value'];
				}
			}
		}
		return ['names' => $names, 'total' => $found['total']];
	}

	public function testConsecutivePagesHaveNoDuplicatesAndSkipNoRows(): void {
		$seen = [];
		for ($page = 1; $page <= 3; $page++) {
			$result = $this->listTable(['page' => (string)$page, 'limit' => '2', 'sort' => 'age']);
			$this->assertSame(5, $result['total']);
			array_push($seen, ...$result['names']);
		}

		$this->assertSame(['Charlie', 'Diana', 'Alice', 'Eve', 'Bob'], $seen);
	}

	public function testOffsetStartsAtTheGivenRow(): void {
		$this->assertSame(['Diana', 'Alice'], $this->listTable(['offset' => '1', 'limit' => '2', 'sort' => 'age'])['names']);
	}

	public function testSortDescending(): void {
		$this->assertSame(['Bob', 'Eve', 'Alice'], $this->listTable(['limit' => '3', 'sort' => '-age'])['names']);
	}

	public function testFilterWithoutOperator(): void {
		$this->assertSame(['names' => ['Alice'], 'total' => 1], $this->listTable(['filter' => ['name' => 'Alice']]));
	}

	public function testFiltersAreCombinedWithAnd(): void {
		$result = $this->listTable(['filter' => ['age' => ['is-greater-than' => '27'], 'department' => 'IT'], 'sort' => 'name']);

		$this->assertSame(['names' => ['Alice', 'Eve'], 'total' => 2], $result);
	}

	public function testTheTotalCountsAllPages(): void {
		$result = $this->listTable(['filter' => ['department' => 'IT'], 'limit' => '1', 'sort' => 'name']);

		$this->assertSame(['names' => ['Alice'], 'total' => 3], $result);
	}

	public function testSearchMatchesTextCells(): void {
		$this->assertSame(['names' => ['Alice', 'Bob'], 'total' => 2], $this->listTable(['search' => 'thompson', 'sort' => 'name']));
	}

	public function testSearchMatchesSelectionLabels(): void {
		$this->assertSame(['names' => ['Bob'], 'total' => 1], $this->listTable(['search' => 'inact']));
	}

	public function testASearchWithoutMatchesIsEmpty(): void {
		$this->assertSame(['names' => [], 'total' => 0], $this->listTable(['search' => 'zzz-no-match']));
	}

	public function testFieldsLimitTheReturnedColumns(): void {
		$found = $this->rowService->findPage($this->query(['fields' => 'name', 'sort' => 'name', 'limit' => '1']), self::$testTableId, null, 'test_user');

		$this->assertSame([$this->columnsByName['name']->getId()], array_column($found['rows'][0]->getData(), 'columnId'));
	}

	public function testOnlyMetadataStillReturnsTheRows(): void {
		$found = $this->rowService->findPage($this->query(['fields' => 'id']), self::$testTableId, null, 'test_user');

		$this->assertCount(5, $found['rows']);
		$this->assertSame([], $found['rows'][0]->getData());
	}

	public function testARequestFilterNeverWidensTheView(): void {
		$view = new View();
		$view->setId(self::VIEW_ID);
		$view->setTableId(self::$testTableId);
		$view->setColumns(json_encode([$this->columnsByName['name']->getId(), $this->columnsByName['department']->getId()]));
		$view->setFilter(json_encode([[['columnId' => $this->columnsByName['department']->getId(), 'operator' => 'is-equal', 'value' => 'IT']]]));
		$this->viewMapper->method('find')->willReturn($view);

		$found = $this->rowService->findPage($this->query(['filter' => ['name' => ['is-not-equal' => 'Charlie']], 'sort' => 'name']), null, self::VIEW_ID, 'test_user');

		$this->assertSame(['names' => ['Alice', 'Eve'], 'total' => 2], $this->namesOf($found));
	}

	public function testASearchInAViewWithoutSearchableColumnsReturnsNothing(): void {
		$view = new View();
		$view->setId(self::VIEW_ID);
		$view->setTableId(self::$testTableId);
		$view->setColumns(json_encode([$this->columnsByName['age']->getId(), $this->columnsByName['score']->getId()]));
		$this->viewMapper->method('find')->willReturn($view);

		$found = $this->rowService->findPage($this->query(['search' => 'Alice']), null, self::VIEW_ID, 'test_user');

		$this->assertSame([], $found['rows']);
		$this->assertSame(0, $found['total']);
	}

	public function testAViewOnlyAcceptsItsOwnColumns(): void {
		$view = new View();
		$view->setId(self::VIEW_ID);
		$view->setTableId(self::$testTableId);
		$view->setColumns(json_encode([$this->columnsByName['name']->getId()]));
		$this->viewMapper->method('find')->willReturn($view);

		$this->expectException(BadRequestError::class);
		$this->rowService->findPage($this->query(['sort' => 'age']), null, self::VIEW_ID, 'test_user');
	}
}
