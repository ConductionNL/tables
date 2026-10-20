<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Db\Column;
use OCA\Tables\Db\Row2;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Helper\ColumnsHelper;
use OCA\Tables\Service\ColumnService;
use OCA\Tables\Service\ColumnTypes\IColumnTypeBusiness;
use OCA\Tables\Service\ImportService;
use OCA\Tables\Service\PermissionsService;
use OCA\Tables\Service\RowService;
use OCA\Tables\Service\TableService;
use OCA\Tables\Service\ViewService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
use OCP\ITempManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportServiceRowErrorsTest extends TestCase {
	private const TABLE_ID = 3;

	private string $csvFile;

	protected function setUp(): void {
		$this->csvFile = tempnam(sys_get_temp_dir(), 'tables-import') . '.csv';
		file_put_contents($this->csvFile, "Mail\nzed@example.org\nnot an address\nyara@example.org\n");
	}

	protected function tearDown(): void {
		unlink($this->csvFile);
	}

	public function testStoresTheValidRowsAndListsTheRefusedOnes(): void {
		$rowService = $this->createMock(RowService::class);
		$rowService->expects($this->exactly(3))->method('create')->willReturnCallback(static function (int $tableId, ?int $viewId, array $data): Row2 {
			if ($data[0]['value'] === 'not an address') {
				throw new BadRequestError('Validation failed: Mail did not match the criteria for Email address, such as jane@example.org');
			}
			return new Row2();
		});

		$stats = $this->createImportService($rowService)->importV2('alice', ImportService::IMPORT_TYPE_USER_FILE, 'mails.csv', self::TABLE_ID, null);

		$this->assertSame(2, $stats->insertedRowsCount);
		$this->assertSame(1, $stats->errorsCount);
		$this->assertSame([
			['row' => 3, 'message' => 'Validation failed: Mail did not match the criteria for Email address, such as jane@example.org'],
		], $stats->rowErrors);
	}

	private function createImportService(RowService $rowService): ImportService {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->willReturn($this->csvFile);
		$file = $this->createMock(File::class);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('mails.csv');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturn($file);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$mail = new Column();
		$mail->setId(11);
		$mail->setTitle('Mail');
		$mail->setType(Column::TYPE_TEXT);
		$mail->setSubtype('email');
		$columnService = $this->createMock(ColumnService::class);
		$columnService->method('findOrCreateColumnsByTitleForTableAsArray')->willReturn([$mail]);

		$business = $this->createMock(IColumnTypeBusiness::class);
		$business->method('canBeParsedDisplayValue')->willReturn(true);
		$business->method('parseDisplayValue')->willReturnCallback(static fn (mixed $value): string => json_encode($value));
		$columnsHelper = $this->createMock(ColumnsHelper::class);
		$columnsHelper->method('getColumnBusinessObject')->willReturn($business);

		$appDataFactory = $this->createMock(IAppDataFactory::class);
		$appDataFactory->method('get')->willReturn($this->createMock(IAppData::class));

		return new ImportService(
			$this->createMock(PermissionsService::class),
			$this->createMock(LoggerInterface::class),
			'alice',
			$rootFolder,
			$columnService,
			$rowService,
			$this->createMock(TableService::class),
			$this->createMock(ViewService::class),
			$this->createMock(IUserManager::class),
			$columnsHelper,
			$this->createMock(IJobList::class),
			$this->createMock(ITempManager::class),
			$appDataFactory,
		);
	}
}
