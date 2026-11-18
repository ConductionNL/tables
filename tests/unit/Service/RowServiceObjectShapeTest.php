<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Activity\ActivityManager;
use OCA\Tables\Db\Column;
use OCA\Tables\Db\Row2;
use OCA\Tables\Db\Table;
use OCA\Tables\Db\TableMapper;
use OCA\Tables\Db\View;
use OCA\Tables\Db\ViewMapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\RowValidationError;
use OCA\Tables\Helper\ColumnsHelper;
use OCA\Tables\Helper\InstanceTimezone;
use OCA\Tables\Model\RowDataInput;
use OCA\Tables\Model\RowObjectInput;
use OCA\Tables\Notification\NotificationHelper;
use OCA\Tables\Service\ColumnTypes\IColumnTypeBusiness;
use OCA\Tables\Service\ColumnTypes\RelationBusiness;
use OCA\Tables\Service\PermissionsService;
use OCA\Tables\Service\RelationService;
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
	/** columns this test adds to the shared table, as technical name => [type, subtype] */
	private const EXTRA_COLUMNS = [
		'owner' => [Column::TYPE_USERGROUP, ''],
		'website' => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINK],
		'mentor' => [Column::TYPE_RELATION, ''],
	];

	/** @var array<string, int> */
	private static array $extraColumnIds = [];

	private RowService $rowService;
	private RowObjectSerializer $serializer;
	private ViewMapper&MockObject $viewMapper;
	/** @var array<string, Column> */
	private array $columnsByName = [];

	protected function setUp(): void {
		parent::setUp();
		$this->setupDependencies();
		if (self::$extraColumnIds === []) {
			foreach (self::EXTRA_COLUMNS as $technicalName => [$type, $subtype]) {
				self::$extraColumnIds[$technicalName] = $this->createTestColumn(self::$testTableId, ['title' => ucfirst($technicalName), 'type' => $type, 'subtype' => $subtype])['id'];
			}
		}
		$this->setupRealColumnMapper(self::$testTableId);

		$columnIds = array_combine(self::COLUMNS, self::$testColumnIds) + self::$extraColumnIds;
		foreach ($columnIds as $technicalName => $columnId) {
			$column = $this->columnMapper->find($columnId);
			$column->setTechnicalName($technicalName);
			$column->setMandatory(false);
			// the shared table leaves the subtype of its text columns empty, which no text column type handles
			if ($column->getType() === Column::TYPE_TEXT && $column->getSubtype() === '') {
				$column->setSubtype(Column::SUBTYPE_TEXT_LINE);
			}
			$this->columnsByName[$technicalName] = $column;
		}
		$this->columnsByName['status']->setSelectionOptions(json_encode([['id' => 0, 'label' => 'Active'], ['id' => 1, 'label' => 'Inactive'], ['id' => 2, 'label' => 'Pending']]));
		$this->columnsByName['skills']->setSelectionOptions(json_encode([['id' => 0, 'label' => 'PHP'], ['id' => 1, 'label' => 'JavaScript'], ['id' => 2, 'label' => 'SQL']]));
		$this->columnsByName['website']->setTextAllowedPattern('url');
		$this->columnsByName['mentor']->setCustomSettings(json_encode([
			Column::RELATION_TYPE => Column::RELATION_TYPE_TABLE,
			Column::RELATION_TARGET_ID => self::$testTableId,
			Column::RELATION_LABEL_COLUMN => $this->columnsByName['name']->getId(),
		]));
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

		// the relation column type reads its targets with the permissions of the session user, which a unit test does not have
		$relationService = $this->createMock(RelationService::class);
		$relationService->method('getRelationData')->willReturnCallback(fn (): array => $this->relationTargets());
		$relationBusiness = new RelationBusiness($this->logger, $relationService);
		$columnsHelper = $this->getMockBuilder(ColumnsHelper::class)
			->setConstructorArgs([$this->userHelper, $this->circleHelper])
			->onlyMethods(['getColumnBusinessObject'])
			->getMock();
		$columnsHelper->method('getColumnBusinessObject')->willReturnCallback(
			fn (Column $column): IColumnTypeBusiness => $column->getType() === Column::TYPE_RELATION ? $relationBusiness : $this->columnsHelper->getColumnBusinessObject($column),
		);

		$this->viewMapper = $this->createMock(ViewMapper::class);
		$this->serializer = new RowObjectSerializer($this->rowSleeveMapper, $instanceTimezone, $this->logger);
		$this->rowService = new RowService(
			$this->logger,
			'test_user',
			$permissionsService,
			$this->columnMapper,
			$this->viewMapper,
			$tableMapper,
			$this->mapper,
			$this->createMock(IEventDispatcher::class),
			$columnsHelper,
			$this->createMock(ActivityManager::class),
			$this->createMock(NotificationHelper::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(RowVisibilityService::class),
			new RowQueryResolver(),
			new RowObjectInputConverter($this->mapper, $instanceTimezone, $this->serializer),
		);
	}

	/**
	 * The rows of the table, as the relation column offers them.
	 *
	 * @return array<int, array{id: int, label: string}>
	 */
	private function relationTargets(): array {
		$targets = [];
		foreach ($this->mapper->findAll([$this->columnsByName['name']->getId()], self::$testTableId, userId: 'test_user') as $row) {
			$targets[$row->getId()] = ['id' => $row->getId(), 'label' => (string)($row->getData()[0]['value'] ?? '')];
		}
		return $targets;
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
	public static function multiSelectionThatIsNotAList(): array {
		return [
			'ids as a string' => ['0,2'],
			'one id' => [1],
			'labels' => ['a,zzz'],
		];
	}

	/**
	 * @dataProvider multiSelectionThatIsNotAList
	 */
	public function testMultiSelectionThatIsNotAListIsReported(mixed $skills): void {
		$this->assertArrayHasKey('skills', $this->errorsOfCreating(['name' => 'Zed', 'skills' => $skills]));
	}

	public function testMultiSelectionWithoutOptionZeroStoresTheGivenIds(): void {
		$this->columnsByName['skills']->setSelectionOptions(json_encode([['id' => 1, 'label' => 'JavaScript'], ['id' => 2, 'label' => 'SQL'], ['id' => 3, 'label' => 'Go']]));

		$created = $this->rowService->create(self::$testTableId, null, new RowObjectInput(['name' => 'Zed', 'skills' => [1, 2]]));

		$this->assertSame([1, 2], $this->readObject($created->getId())['skills']);
	}

	public function testErrorsAreInTheSameOrderWhetherARelationUuidExistsOrNot(): void {
		$otherTableId = $this->createTestTable()['id'];
		// the database outlives a test run, so the uuids differ per run
		$foreignUuid = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));
		$this->createTestRow($otherTableId, ['uuid' => $foreignUuid]);
		$unknownUuid = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));

		$foreign = $this->errorsOfCreating(['status' => 99, 'mentor' => $foreignUuid]);
		$unknown = $this->errorsOfCreating(['status' => 99, 'mentor' => $unknownUuid]);

		$this->assertSame(array_keys($unknown), array_keys($foreign));
		$this->assertSame($unknown['mentor'], $foreign['mentor']);
	}

	public function testListForATextColumnIsReported(): void {
		$this->assertSame(['name'], array_keys($this->errorsOfCreating(['name' => ['a']])));
	}

	public function testLimitViolationIsReportedWithTheOtherInvalidValues(): void {
		$this->columnsByName['age']->setNumberMax(150.0);

		$errors = $this->errorsOfCreating(['name' => 'Zed', 'age' => 500, 'birthday' => 'yesterday']);

		ksort($errors);
		$this->assertSame(['age', 'birthday'], array_keys($errors));
	}

	public function testCellsInputReportsOnlyTheFirstMissingMandatoryColumn(): void {
		$this->columnsByName['department']->setMandatory(true);
		$this->columnsByName['surname']->setMandatory(true);

		try {
			$this->rowService->create(self::$testTableId, null, (new RowDataInput())->add($this->columnsByName['name']->getId(), 'Zed'));
			$this->fail('The row was created without its mandatory values.');
		} catch (BadRequestError $e) {
			$this->assertNotInstanceOf(RowValidationError::class, $e);
			$this->assertSame('Mandatory column "Department" cannot be empty.', $e->getMessage());
		}
	}

	public function testMissingMandatoryValueIsReportedOnItsProperty(): void {
		$this->columnsByName['surname']->setMandatory(true);

		$this->assertArrayHasKey('surname', $this->errorsOfCreating(['name' => 'Zed', 'age' => 'many']));
	}

	public function testZeroAsADecimalStringIsStored(): void {
		$created = $this->rowService->create(self::$testTableId, null, new RowObjectInput(['name' => 'Zero', 'age' => '0.0']));

		$this->assertEquals(0, $this->readObject($created->getId())['age']);
	}

	public function testCellsInputStaysLenient(): void {
		$row = $this->rowService->create(self::$testTableId, null, (new RowDataInput())
			->add($this->columnsByName['name']->getId(), 'Zed')
			->add($this->columnsByName['age']->getId(), 'many'));

		$this->assertNotNull($row->getId());
	}

	public function testWhatIsReadCanBeWrittenBackUnchanged(): void {
		$this->columnsByName['experience_years']->setTechnicalName('');
		$experienceKey = 'column_' . $this->columnsByName['experience_years']->getId();
		$target = $this->rowService->create(self::$testTableId, null, new RowObjectInput(['name' => 'Xavier']));
		$created = $this->rowService->create(self::$testTableId, null, new RowObjectInput([
			'name' => 'Yara',
			'age' => 41,
			'birthday' => '2026-10-16T12:30:00Z',
			'score' => 7.5,
			'status' => 1,
			'skills' => [0, 2],
			'is_available' => true,
			'owner' => [['id' => 'admin', 'type' => 0]],
			'website' => ['title' => 'Nextcloud', 'url' => 'https://nextcloud.com', 'providerId' => 'url'],
			'mentor' => $target->getId(),
			$experienceKey => 12,
		]));

		$read = $this->readObject($created->getId());
		$this->assertSame('2026-10-16T14:30:00+02:00', $read['birthday']);
		$this->assertSame('true', $read['is_available']);
		$this->assertSame([0, 2], $read['skills']);
		$this->assertSame(1, $read['status']);
		$this->assertSame('admin', $read['owner'][0]['id']);
		$this->assertArrayHasKey('displayName', $read['owner'][0]);
		$this->assertSame(['title' => 'Nextcloud', 'url' => 'https://nextcloud.com', 'providerId' => 'url'], $read['website']);
		$this->assertSame($this->readObject($target->getId())['@self']['id'], $read['mentor']);
		$this->assertEquals(12, $read[$experienceKey]);

		$updated = $this->rowService->updateSet($created->getId(), null, new RowObjectInput($read), 'test_user', self::$testTableId);
		$this->assertSame([], $updated->getChangedCells());
		$this->assertSameObject($read, $this->readObject($created->getId()));

		$this->useView(['owner']);
		$this->rowService->updateSet($created->getId(), self::VIEW_ID, new RowObjectInput($read), 'test_user', null);
		$this->assertSameObject($read, $this->readObject($created->getId()));
	}

	public function testWriteBackAfterTheRelationTargetWasDeletedClearsTheRelation(): void {
		$target = $this->rowService->create(self::$testTableId, null, new RowObjectInput(['name' => 'Gone']));
		$created = $this->rowService->create(self::$testTableId, null, new RowObjectInput(['name' => 'Ida', 'mentor' => $target->getId()]));
		$this->mapper->delete($target);

		$read = $this->readObject($created->getId());
		$this->assertNull($read['mentor']);
		$this->assertEquals($target->getId(), $this->storedValue($created->getId(), 'mentor'));

		$this->rowService->updateSet($created->getId(), null, new RowObjectInput($read), 'test_user', self::$testTableId);

		$this->assertNull($this->readObject($created->getId())['mentor']);
		$this->assertNull($this->storedValue($created->getId(), 'mentor'));
	}

	public function testWriteBackOfADeletedReadOnlyRelationTargetSucceeds(): void {
		$target = $this->rowService->create(self::$testTableId, null, new RowObjectInput(['name' => 'Gone']));
		$created = $this->rowService->create(self::$testTableId, null, new RowObjectInput(['name' => 'Ida', 'mentor' => $target->getId()]));
		$this->mapper->delete($target);
		$this->useView(['mentor']);

		$read = $this->readObject($created->getId());
		$this->rowService->updateSet($created->getId(), self::VIEW_ID, new RowObjectInput($read), 'test_user', null);

		$this->assertNull($this->readObject($created->getId())['mentor']);
	}

	/**
	 * @return array<string, array{bool, bool}>
	 */
	public static function mandatoryRelation(): array {
		return [
			'mandatory in the table' => [true, false],
			'mandatory in the view' => [false, true],
		];
	}

	/**
	 * @dataProvider mandatoryRelation
	 */
	public function testWriteBackOfADeletedMandatoryRelationTargetKeepsTheRelation(bool $mandatoryInTable, bool $mandatoryInView): void {
		$target = $this->rowService->create(self::$testTableId, null, new RowObjectInput(['name' => 'Gone']));
		$created = $this->rowService->create(self::$testTableId, null, new RowObjectInput(['name' => 'Ida', 'mentor' => $target->getId()]));
		$this->mapper->delete($target);
		$this->columnsByName['mentor']->setMandatory($mandatoryInTable);
		$this->useView([], $mandatoryInView ? ['mentor'] : []);

		$read = $this->readObject($created->getId());
		$read['name'] = 'Ida B';
		$this->rowService->updateSet($created->getId(), self::VIEW_ID, new RowObjectInput($read), 'test_user', null);

		$this->assertSame('Ida B', $this->readObject($created->getId())['name']);
		$this->assertEquals($target->getId(), $this->storedValue($created->getId(), 'mentor'));
	}

	/**
	 * @return array<string, array{list<string>}>
	 */
	public static function readOnlyNames(): array {
		return [
			'checkbox read-only' => [['is_available']],
			'checkbox writable' => [[]],
		];
	}

	/**
	 * @dataProvider readOnlyNames
	 * @param list<string> $readOnlyNames
	 */
	public function testMissingCheckboxIsWrittenBackThroughAViewAsNull(array $readOnlyNames): void {
		$this->useView($readOnlyNames);
		$created = $this->rowService->create(self::$testTableId, self::VIEW_ID, new RowObjectInput(['name' => 'Una', 'is_available' => null]));
		$read = $this->readObject($created->getId());
		$this->assertNull($read['is_available']);

		$this->rowService->updateSet($created->getId(), self::VIEW_ID, new RowObjectInput($read), 'test_user', null);

		$this->assertNull($this->readObject($created->getId())['is_available']);
	}

	/**
	 * @param array<string, mixed> $expected
	 * @param array<string, mixed> $actual
	 */
	private function assertSameObject(array $expected, array $actual): void {
		unset($expected['@self'], $actual['@self']);
		$this->assertSame($expected, $actual);
	}

	private function storedValue(int $rowId, string $technicalName): mixed {
		$columnId = $this->columnsByName[$technicalName]->getId();
		foreach ($this->rowService->findInNode($rowId, self::$testTableId, null, 'test_user')->getData() as $cell) {
			if ($cell['columnId'] === $columnId) {
				return $cell['value'];
			}
		}
		return null;
	}

	/**
	 * A view with every column of the table, of which the given ones are read-only or mandatory.
	 *
	 * @param list<string> $readOnlyNames
	 * @param list<string> $mandatoryNames
	 */
	private function useView(array $readOnlyNames, array $mandatoryNames = []): void {
		$settings = [];
		foreach (array_values($this->columnsByName) as $index => $column) {
			$name = RowObjectInputConverter::nameOf($column);
			$settings[] = ['columnId' => $column->getId(), 'order' => $index + 1, 'readonly' => in_array($name, $readOnlyNames, true), 'mandatory' => in_array($name, $mandatoryNames, true)];
		}
		$view = new View();
		$view->setId(self::VIEW_ID);
		$view->setTableId(self::$testTableId);
		$view->setColumns(json_encode($settings));
		$this->viewMapper->method('find')->willReturn($view);
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
	 * Stores a row with the given cells as they are, without the checks of the column types.
	 *
	 * @param array<string, mixed> $valuesByName
	 */
	private function storeRow(array $valuesByName): int {
		$row = new Row2();
		$row->setTableId(self::$testTableId);
		$data = [];
		foreach ($valuesByName as $technicalName => $value) {
			$data[] = ['columnId' => $this->columnsByName[$technicalName]->getId(), 'value' => $value];
		}
		$row->setData($data);
		return $this->mapper->insert($row, 'test_user')->getId();
	}

	public function testALinkStoredByTheUiIsWrittenBackWithoutBeingRewritten(): void {
		$uiLink = '{"title":"Nextcloud","subline":"URL","providerId":"url","value":"https://nextcloud.com/a/b"}';
		$rowId = $this->storeRow(['name' => 'Lars', 'website' => $uiLink]);
		$read = $this->readObject($rowId);
		$this->assertSame(['title' => 'Nextcloud', 'url' => 'https://nextcloud.com/a/b', 'providerId' => 'url'], $read['website']);

		$updated = $this->rowService->updateSet($rowId, null, new RowObjectInput($read), 'test_user', self::$testTableId);
		$this->assertSame([], $updated->getChangedCells());
		$this->assertSame($uiLink, $this->storedValue($rowId, 'website'));

		$this->useView(['website']);
		$this->rowService->updateSet($rowId, self::VIEW_ID, new RowObjectInput($read), 'test_user', null);
		$this->assertSame($uiLink, $this->storedValue($rowId, 'website'));
	}

	/**
	 * @return array<string, array{string, mixed, mixed}>
	 */
	public static function staleValue(): array {
		return [
			'a user that no longer exists' => ['owner', [['id' => 'no_such_user', 'type' => 0]], [['id' => 'no_such_user', 'type' => 0, 'displayName' => 'no_such_user']]],
			'a number above the lowered maximum' => ['age', 500, 500],
			'a datetime with seconds' => ['birthday', '2026-10-16 14:30:15', '2026-10-16T14:30:15+02:00'],
			'a datetime in the daylight saving gap' => ['birthday', '2026-03-29 02:30', '2026-03-29T03:30:00+02:00'],
			'a datetime in an unknown format' => ['birthday', 'soon', 'soon'],
		];
	}

	/**
	 * @dataProvider staleValue
	 */
	public function testAStaleValueOfAReadOnlyColumnIsWrittenBackUnchanged(string $technicalName, mixed $stored, mixed $read): void {
		$rowId = $this->storeRow(['name' => 'Stan', $technicalName => $stored]);
		$this->columnsByName['age']->setNumberMax(150.0);
		$storedBefore = $this->storedValue($rowId, $technicalName);
		$object = $this->readObject($rowId);
		$this->assertEquals($read, $object[$technicalName]);
		$this->useView([$technicalName]);

		$object['name'] = 'Stan B';
		$this->rowService->updateSet($rowId, self::VIEW_ID, new RowObjectInput($object), 'test_user', null);

		$this->assertSame('Stan B', $this->readObject($rowId)['name']);
		$this->assertSame($storedBefore, $this->storedValue($rowId, $technicalName));
	}

	/**
	 * @dataProvider staleValue
	 */
	public function testAStaleValueOfAWritableColumnIsWrittenBackUnchanged(string $technicalName, mixed $stored): void {
		$rowId = $this->storeRow(['name' => 'Stan', $technicalName => $stored]);
		$this->columnsByName['age']->setNumberMax(150.0);
		$storedBefore = $this->storedValue($rowId, $technicalName);
		$object = $this->readObject($rowId);

		$object['name'] = 'Stan B';
		$updated = $this->rowService->updateSet($rowId, null, new RowObjectInput($object), 'test_user', self::$testTableId);

		$this->assertSame([$this->columnsByName['name']->getId()], array_column($updated->getChangedCells(), 'columnId'));
		$this->assertSame($storedBefore, $this->storedValue($rowId, $technicalName));
	}

	public function testAValueTheConverterRefusesIsReportedAlsoWhenItReadsLikeTheStoredOne(): void {
		$rowId = $this->storeRow(['name' => 'Rolf', 'birthday' => '2026-03-02 14:30', 'skills' => [0, 2]]);

		try {
			$this->rowService->updateSet($rowId, null, new RowObjectInput(['birthday' => '2026-02-30T14:30', 'skills' => ['0', '2']]), 'test_user', self::$testTableId);
			$this->fail('A value the converter refuses was taken for the stored one.');
		} catch (RowValidationError $e) {
			$this->assertSame(['birthday', 'skills'], array_keys($e->errors));
		}
	}

	public function testAChangedValueOfAColumnWithAStaleValueIsValidated(): void {
		$rowId = $this->storeRow(['name' => 'Stan', 'age' => 500]);
		$this->columnsByName['age']->setNumberMax(150.0);

		try {
			$this->rowService->updateSet($rowId, null, new RowObjectInput(['age' => 501]), 'test_user', self::$testTableId);
			$this->fail('A changed value above the maximum was accepted.');
		} catch (RowValidationError $e) {
			$this->assertSame(['age'], array_keys($e->errors));
		}
		$this->useView(['age']);
		try {
			$this->rowService->updateSet($rowId, self::VIEW_ID, new RowObjectInput(['age' => 100]), 'test_user', null);
			$this->fail('A changed read-only value was accepted.');
		} catch (RowValidationError $e) {
			$this->assertSame(['age' => 'This column is read-only in this view.'], $e->errors);
		}
		$this->assertEquals(500, $this->storedValue($rowId, 'age'));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readObject(int $rowId): array {
		return $this->serializer->serialize($this->rowService->findInNode($rowId, self::$testTableId, null, 'test_user'));
	}
}
