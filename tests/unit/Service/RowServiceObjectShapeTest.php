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
use OCA\Tables\Errors\RowValidationError;
use OCA\Tables\Helper\InstanceTimezone;
use OCA\Tables\Model\RowDataInput;
use OCA\Tables\Model\RowObjectInput;
use OCA\Tables\Notification\NotificationHelper;
use OCA\Tables\Service\PermissionsService;
use OCA\Tables\Service\RowObjectInputConverter;
use OCA\Tables\Service\RowObjectSerializer;
use OCA\Tables\Service\RowQueryResolver;
use OCA\Tables\Service\RowService;
use OCA\Tables\Service\RowVisibilityService;
use OCA\Tables\Tests\Unit\Database\DatabaseTestCase;
use OCA\Tables\Tests\Unit\Db\Row2MapperTestDependencies;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Writes and reads rows of the shared Row2Mapper test table in the object shape, through
 * the real column types.
 */
class RowServiceObjectShapeTest extends DatabaseTestCase {
	use Row2MapperTestDependencies;

	private const COLUMNS = ['name', 'age', 'birthday', 'department', 'score', 'surname', 'status', 'skills', 'is_available', 'experience_years'];
	private const VIEW_ID = 1;

	private RowService $rowService;
	private RowObjectSerializer $serializer;
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
			$column->setMandatory(false);
			// the shared table leaves the subtype of its text columns empty, which no text column type handles
			if ($column->getType() === Column::TYPE_TEXT) {
				$column->setSubtype(Column::SUBTYPE_TEXT_LINE);
			}
			$this->columnsByName[$technicalName] = $column;
		}
		$this->columnsByName['status']->setSelectionOptions(json_encode([['id' => 0, 'label' => 'Active'], ['id' => 1, 'label' => 'Inactive'], ['id' => 2, 'label' => 'Pending']]));
		$this->columnsByName['skills']->setSelectionOptions(json_encode([['id' => 0, 'label' => 'PHP'], ['id' => 1, 'label' => 'JavaScript'], ['id' => 2, 'label' => 'SQL']]));
		$columns = array_values($this->columnsByName);
		$this->columnMapper->method('findAllByTable')->willReturn($columns);
		$this->columnMapper->method('findAll')->willReturnCallback(
			fn (array $columnIds): array => array_values(array_filter($columns, static fn (Column $column): bool => in_array($column->getId(), $columnIds, true))),
		);

		$permissionsService = $this->createMock(PermissionsService::class);
		$permissionsService->method('canReadRowsByElementId')->willReturn(true);
		$permissionsService->method('canCreateRows')->willReturn(true);
		$permissionsService->method('canUpdateRowsByTableId')->willReturn(true);
		$permissionsService->method('canUpdateRowsByViewId')->willReturn(true);
		$tableMapper = $this->createMock(TableMapper::class);
		$tableMapper->method('find')->willReturn(new Table());

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('Europe/Amsterdam');
		$instanceTimezone = new InstanceTimezone($config);

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
			$this->createMock(RowVisibilityService::class),
			new RowQueryResolver(),
			new RowObjectInputConverter($this->mapper, $instanceTimezone),
		);
		$this->serializer = new RowObjectSerializer($this->rowSleeveMapper, $instanceTimezone, $this->logger);
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, string>
	 */
	private function errorsOfCreating(array $values, ?int $viewId = null): array {
		try {
			$this->rowService->create($viewId === null ? self::$testTableId : null, $viewId, new RowObjectInput($values));
		} catch (RowValidationError $e) {
			return $e->errors;
		}
		$this->fail('The row was created although it has invalid values.');
	}

	public function testEveryInvalidPropertyIsReported(): void {
		$errors = $this->errorsOfCreating([
			'name' => 'Zed',
			'age' => 'many',
			'birthday' => 'yesterday',
			'status' => 99,
			'nickname' => 'Z',
		]);

		ksort($errors);
		$this->assertSame(['age', 'birthday', 'nickname', 'status'], array_keys($errors));
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function multiSelectionThatParsesToNothing(): array {
		return [
			'ids as a string' => ['0,2'],
			'one id' => [1],
		];
	}

	/**
	 * @dataProvider multiSelectionThatParsesToNothing
	 */
	public function testMultiSelectionThatWouldBeStoredAsEmptyIsReported(mixed $skills): void {
		$this->assertArrayHasKey('skills', $this->errorsOfCreating(['name' => 'Zed', 'skills' => $skills]));
	}

	public function testListForATextColumnIsReported(): void {
		$this->assertSame(['name'], array_keys($this->errorsOfCreating(['name' => ['a']])));
	}

	public function testMissingMandatoryValueIsReportedOnItsProperty(): void {
		$this->columnsByName['surname']->setMandatory(true);

		$this->assertArrayHasKey('surname', $this->errorsOfCreating(['name' => 'Zed', 'age' => 'many']));
	}

	public function testCellsInputStaysLenient(): void {
		$row = $this->rowService->create(self::$testTableId, null, (new RowDataInput())
			->add($this->columnsByName['name']->getId(), 'Zed')
			->add($this->columnsByName['age']->getId(), 'many'));

		$this->assertNotNull($row->getId());
	}

	public function testWhatIsReadCanBeWrittenBackUnchanged(): void {
		$created = $this->rowService->create(self::$testTableId, null, new RowObjectInput([
			'name' => 'Yara',
			'age' => 41,
			'birthday' => '2026-10-16T12:30:00Z',
			'score' => 7.5,
			'status' => 1,
			'skills' => [0, 2],
			'is_available' => true,
		]));

		$read = $this->readObject($created->getId());
		$this->assertSame('2026-10-16T14:30:00+02:00', $read['birthday']);
		$this->assertSame('true', $read['is_available']);
		$this->assertSame([0, 2], $read['skills']);
		$this->assertSame(1, $read['status']);

		$this->rowService->updateSet($created->getId(), null, new RowObjectInput($read), 'test_user', self::$testTableId);

		$readAgain = $this->readObject($created->getId());
		unset($read['@self'], $readAgain['@self']);
		$this->assertSame($read, $readAgain);
	}

	/**
	 * A view with name and the read-only age, without the other columns of the table.
	 */
	private function useViewWithReadOnlyAge(): void {
		$view = new View();
		$view->setId(self::VIEW_ID);
		$view->setTableId(self::$testTableId);
		$view->setColumns(json_encode([
			['columnId' => $this->columnsByName['name']->getId(), 'order' => 1],
			['columnId' => $this->columnsByName['age']->getId(), 'order' => 2, 'readonly' => true],
		]));
		$this->viewMapper->method('find')->willReturn($view);
	}

	public function testUnchangedReadOnlyValueOfAViewIsWrittenBack(): void {
		$this->useViewWithReadOnlyAge();
		$created = $this->rowService->create(self::$testTableId, null, new RowObjectInput(['name' => 'Vera', 'age' => 30]));

		$this->rowService->updateSet($created->getId(), self::VIEW_ID, new RowObjectInput(['name' => 'Vera B', 'age' => 30]), 'test_user', null);

		$read = $this->readObject($created->getId());
		$this->assertSame('Vera B', $read['name']);
		$this->assertEquals(30, $read['age']);
	}

	public function testChangedReadOnlyValueOfAViewIsReported(): void {
		$this->useViewWithReadOnlyAge();
		$created = $this->rowService->create(self::$testTableId, null, new RowObjectInput(['name' => 'Vera', 'age' => 30]));

		try {
			$this->rowService->updateSet($created->getId(), self::VIEW_ID, new RowObjectInput(['name' => 'Vera B', 'age' => 31]), 'test_user', null);
			$this->fail('A changed read-only value was accepted.');
		} catch (RowValidationError $e) {
			$this->assertSame(['age' => 'This column is read-only in this view.'], $e->errors);
		}
		$this->assertSame(['age' => 'This column is read-only in this view.'], $this->errorsOfCreating(['name' => 'Wim', 'age' => 31], self::VIEW_ID));
		$this->assertSame('Vera', $this->readObject($created->getId())['name']);
	}

	public function testColumnOutsideTheViewIsReported(): void {
		$this->useViewWithReadOnlyAge();

		$this->assertSame(
			['department' => 'There is no column with this technical name.'],
			$this->errorsOfCreating(['name' => 'Wim', 'department' => 'IT'], self::VIEW_ID),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readObject(int $rowId): array {
		return $this->serializer->serialize($this->rowService->findInNode($rowId, self::$testTableId, null, 'test_user'));
	}
}
