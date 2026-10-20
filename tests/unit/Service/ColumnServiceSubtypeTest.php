<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Activity\ActivityManager;
use OCA\Tables\Db\Column;
use OCA\Tables\Db\ColumnMapper;
use OCA\Tables\Db\Format;
use OCA\Tables\Db\Table;
use OCA\Tables\Db\TableMapper;
use OCA\Tables\Dto\Column as ColumnDto;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Helper\UserHelper;
use OCA\Tables\Notification\NotificationHelper;
use OCA\Tables\Service\ColumnService;
use OCA\Tables\Service\FormatService;
use OCA\Tables\Service\PermissionsService;
use OCA\Tables\Service\RowService;
use OCA\Tables\Service\ViewService;
use OCA\Tables\Validation\ColumnDtoValidator;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ColumnServiceSubtypeTest extends TestCase {
	private const TABLE_ID = 7;

	private ColumnMapper&MockObject $columnMapper;
	private ColumnService $service;

	protected function setUp(): void {
		$this->columnMapper = $this->createMock(ColumnMapper::class);
		$this->columnMapper->method('findAllByTable')->willReturn([]);
		$this->columnMapper->method('insert')->willReturnArgument(0);
		$this->columnMapper->method('update')->willReturnArgument(0);

		$permissionsService = $this->createMock(PermissionsService::class);
		$permissionsService->method('canCreateColumns')->willReturn(true);
		$permissionsService->method('canUpdateColumnsByTableId')->willReturn(true);

		$table = new Table();
		$table->setId(self::TABLE_ID);
		$tableMapper = $this->createMock(TableMapper::class);
		$tableMapper->method('find')->willReturn($table);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$email = new Format();
		$email->setTechnicalName('email');
		$email->setType(Column::TYPE_TEXT);
		$formatService = $this->createMock(FormatService::class);
		$formatService->method('findUsableFormat')->willReturnCallback(
			static fn (string $technicalName, string $columnType): ?Format => $technicalName === 'email' && $columnType === Column::TYPE_TEXT ? $email : null,
		);

		$this->service = new ColumnService(
			$permissionsService,
			$this->createMock(LoggerInterface::class),
			'alice',
			$this->columnMapper,
			$tableMapper,
			$this->createMock(ViewService::class),
			$this->createMock(RowService::class),
			$this->createMock(ActivityManager::class),
			$this->createMock(NotificationHelper::class),
			$l10n,
			$this->createMock(UserHelper::class),
			new ColumnDtoValidator(),
			$formatService,
		);
	}

	public function testCreatesATextColumnWithAKnownFormat(): void {
		$column = $this->service->create('alice', self::TABLE_ID, null, $this->dto(Column::TYPE_TEXT, 'email'));

		$this->assertSame('email', $column->getSubtype());
		$this->assertSame(Column::SUBTYPE_TEXT_LINE, $column->getBaseSubtype());
		$this->assertFalse($column->isBroken());
	}

	public function testCreatesATextColumnWithoutASubtype(): void {
		$column = $this->service->create('alice', self::TABLE_ID, null, $this->dto(Column::TYPE_TEXT, ''));

		$this->assertSame(Column::SUBTYPE_TEXT_LINE, $column->getBaseSubtype());
	}

	public function testRefusesToCreateATextColumnWithAnUnknownFormat(): void {
		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage('There is no format nl_bsn for columns of type text.');

		$this->service->create('alice', self::TABLE_ID, null, $this->dto(Column::TYPE_TEXT, 'nl_bsn'));
	}

	public function testRefusesAFormatMeantForAnotherType(): void {
		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage('There is no format email for columns of type number.');

		$this->service->create('alice', self::TABLE_ID, null, $this->dto(Column::TYPE_NUMBER, 'email'));
	}

	public function testRefusesAnUnknownSubtypeOnTypesWithoutFormats(): void {
		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage('Columns of type selection have no subtype email.');

		$this->service->create('alice', self::TABLE_ID, null, $this->dto(Column::TYPE_SELECTION, 'email'));
	}

	public function testEditsABrokenColumnThatKeepsItsSubtype(): void {
		$this->givenStoredColumn(Column::TYPE_TEXT, 'nl_bsn');

		$column = $this->service->update(1, 'alice', new ColumnDto(title: 'Citizen number', type: Column::TYPE_TEXT, subtype: 'nl_bsn'));

		$this->assertSame('Citizen number', $column->getTitle());
		$this->assertTrue($column->isBroken());
	}

	public function testRefusesToChangeToAnUnknownFormat(): void {
		$this->givenStoredColumn(Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINE);

		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage('There is no format nl_bsn for columns of type text.');

		$this->service->update(1, 'alice', new ColumnDto(subtype: 'nl_bsn'));
	}

	public function testChangesToAKnownFormat(): void {
		$this->givenStoredColumn(Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINE);

		$column = $this->service->update(1, 'alice', new ColumnDto(subtype: 'email'));

		$this->assertSame('email', $column->getSubtype());
		$this->assertFalse($column->isBroken());
	}

	public function testRefusesToChangeTheTypeWhenTheSubtypeDoesNotFitIt(): void {
		$this->givenStoredColumn(Column::TYPE_TEXT, 'email');

		$this->expectException(BadRequestError::class);

		$this->service->update(1, 'alice', new ColumnDto(type: Column::TYPE_SELECTION));
	}

	private function dto(string $type, string $subtype): ColumnDto {
		return new ColumnDto(title: 'Contact', technicalName: 'contact', type: $type, subtype: $subtype);
	}

	private function givenStoredColumn(string $type, string $subtype): void {
		$column = new Column();
		$column->setId(1);
		$column->setTableId(self::TABLE_ID);
		$column->setTitle('Contact');
		$column->setCreatedBy('alice');
		$column->setType($type);
		$column->setSubtype($subtype);
		$this->columnMapper->method('find')->willReturn($column);
	}
}
