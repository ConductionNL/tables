<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Activity\ActivityManager;
use OCA\Tables\Db\Column;
use OCA\Tables\Db\Format;
use OCA\Tables\Db\Table;
use OCA\Tables\Db\TableMapper;
use OCA\Tables\Db\ViewMapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\RowValidationError;
use OCA\Tables\Formats\FormatLabelTranslator;
use OCA\Tables\Formats\FormatRuleEvaluator;
use OCA\Tables\Helper\InstanceTimezone;
use OCA\Tables\Model\RowDataInput;
use OCA\Tables\Model\RowObjectInput;
use OCA\Tables\Notification\NotificationHelper;
use OCA\Tables\Service\FormatService;
use OCA\Tables\Service\FormatValueValidator;
use OCA\Tables\Service\PermissionsService;
use OCA\Tables\Service\RowObjectInputConverter;
use OCA\Tables\Service\RowObjectSerializer;
use OCA\Tables\Service\RowQueryResolver;
use OCA\Tables\Service\RowService;
use OCA\Tables\Tests\Unit\Database\DatabaseTestCase;
use OCA\Tables\Tests\Unit\Db\Row2MapperTestDependencies;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Writes and reads rows of the shared Row2Mapper test table in the object shape, through
 * the real column types.
 */
class RowServiceObjectShapeTest extends DatabaseTestCase {
	use Row2MapperTestDependencies;

	private const COLUMNS = ['name', 'age', 'birthday', 'department', 'score', 'surname', 'status', 'skills', 'is_available', 'experience_years'];

	private RowService $rowService;
	private RowObjectSerializer $serializer;
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
		$tableMapper = $this->createMock(TableMapper::class);
		$tableMapper->method('find')->willReturn(new Table());

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('Europe/Amsterdam');
		$instanceTimezone = new InstanceTimezone($config);

		$this->rowService = new RowService(
			$this->logger,
			'test_user',
			$permissionsService,
			$this->columnMapper,
			$this->createMock(ViewMapper::class),
			$tableMapper,
			$this->mapper,
			$this->createMock(IEventDispatcher::class),
			$this->columnsHelper,
			$this->createMock(ActivityManager::class),
			$this->createMock(NotificationHelper::class),
			$this->createMock(IDBConnection::class),
			new RowQueryResolver(),
			new RowObjectInputConverter($this->mapper, $instanceTimezone),
			$this->createFormatValueValidator(),
		);
		$this->serializer = new RowObjectSerializer($this->columnMapper, $this->rowSleeveMapper, $instanceTimezone, $this->logger);
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, string>
	 */
	private function errorsOfCreating(array $values): array {
		try {
			$this->rowService->create(self::$testTableId, null, new RowObjectInput($values));
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

	public function testAValueThatFailsTheFormatIsReportedOnItsProperty(): void {
		$this->columnsByName['surname']->setSubtype('email');

		$errors = $this->errorsOfCreating(['name' => 'Zed', 'surname' => 'not an address']);

		$this->assertSame(['surname' => 'Validation failed: Surname did not match the criteria for Email address, such as jane@example.org'], $errors);
	}

	public function testAValueThatFailsTheFormatIsRefusedInCells(): void {
		$this->columnsByName['surname']->setSubtype('email');

		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage('Validation failed: Surname did not match the criteria for Email address, such as jane@example.org');

		$this->rowService->create(self::$testTableId, null, (new RowDataInput())
			->add($this->columnsByName['surname']->getId(), 'not an address'));
	}

	public function testAValueThatMatchesTheFormatIsStored(): void {
		$this->columnsByName['surname']->setSubtype('email');

		$created = $this->rowService->create(self::$testTableId, null, new RowObjectInput(['surname' => 'zed@example.org']));

		$this->assertSame('zed@example.org', $this->readObject($created->getId())['surname']);
	}

	public function testTheAllowedPatternIsEnforcedOnAFormattedColumn(): void {
		$this->columnsByName['surname']->setSubtype('email');
		$this->columnsByName['surname']->setTextAllowedPattern('@example\\.org$');

		$errors = $this->errorsOfCreating(['surname' => 'zed@example.com']);

		$this->assertSame(['surname' => 'Validation failed: Surname did not match the pattern @example\\.org$'], $errors);
	}

	public function testTheAllowedPatternIsNotEnforcedOnABuiltInSubtype(): void {
		$this->columnsByName['surname']->setTextAllowedPattern('^[0-9]+$');

		$created = $this->rowService->create(self::$testTableId, null, new RowObjectInput(['surname' => 'Smith']));

		$this->assertSame('Smith', $this->readObject($created->getId())['surname']);
	}

	public function testABrokenColumnStoresValuesUnchecked(): void {
		$this->columnsByName['surname']->setSubtype('nl_bsn');

		$created = $this->rowService->create(self::$testTableId, null, new RowObjectInput(['surname' => 'anything']));

		$this->assertSame('anything', $this->readObject($created->getId())['surname']);
	}

	private function createFormatValueValidator(): FormatValueValidator {
		$email = new Format();
		$email->setTechnicalName('email');
		$email->setLabel('Email address');
		$email->setExample('jane@example.org');
		$email->setType(Column::TYPE_TEXT);
		$email->setRule('{"match":[{"var":"value"},"^[^@ ]+@[^@ ]+$"]}');
		$formatService = $this->createMock(FormatService::class);
		$formatService->method('findUsableFormat')->willReturnCallback(
			static fn (string $technicalName, string $columnType): ?Format => $technicalName === 'email' && $columnType === Column::TYPE_TEXT ? $email : null,
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new FormatValueValidator($formatService, new FormatRuleEvaluator(), $this->mapper, $this->columnMapper, new FormatLabelTranslator($l10n), $l10n, $this->logger);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readObject(int $rowId): array {
		return $this->serializer->serialize($this->rowService->findInNode($rowId, self::$testTableId, null, 'test_user'));
	}
}
