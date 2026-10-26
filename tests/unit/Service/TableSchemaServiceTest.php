<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Db\Column;
use OCA\Tables\Db\ColumnMapper;
use OCA\Tables\Db\FormatMapper;
use OCA\Tables\Dto\Column as ColumnDto;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\FormatImportConflictError;
use OCA\Tables\Formats\FormatPackReader;
use OCA\Tables\Formats\FormatRuleEvaluator;
use OCA\Tables\JsonSchema\TableSchemaExporter;
use OCA\Tables\JsonSchema\TableSchemaParser;
use OCA\Tables\Service\ColumnService;
use OCA\Tables\Service\FormatPackService;
use OCA\Tables\Service\FormatService;
use OCA\Tables\Service\TableSchemaService;
use OCA\Tables\Service\TableService;
use OCA\Tables\Tests\Unit\JsonSchema\SchemaFixtures;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TableSchemaServiceTest extends TestCase {
	private const EXPORT_FILE = __DIR__ . '/../../formats/table-schema-export.json';

	private ColumnService&MockObject $columnService;
	private ColumnMapper&MockObject $columnMapper;
	private FormatPackService&MockObject $formatPackService;
	private bool $isAdmin = true;
	/** @var list<array{technicalName: string, status: string}> what the installed formats say about the definitions */
	private array $formatPreview = [];
	/** @var list<array<string, mixed>> */
	private array $importedColumns = [];
	private TableSchemaService $service;
	private TableService&MockObject $tableService;
	private FormatService&MockObject $formatService;
	private IGroupManager&MockObject $groupManager;
	private IL10N&MockObject $l10n;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$installed = SchemaFixtures::formats();
		$formatService = $this->createMock(FormatService::class);
		$formatService->method('findUsableFormat')->willReturnCallback(static fn (string $technicalName): mixed => $installed[$technicalName] ?? null);

		$tableService = $this->createMock(TableService::class);
		$tableService->method('find')->willReturn(SchemaFixtures::table());
		$tableService->method('create')->willReturn(SchemaFixtures::table());

		$this->columnService = $this->createMock(ColumnService::class);
		$this->columnService->method('findAllByTable')->willReturn(SchemaFixtures::columns());
		$this->columnService->method('importColumn')->willReturnCallback(function ($table, array $column): int {
			$this->importedColumns[] = $column;
			return count($this->importedColumns);
		});

		$this->columnMapper = $this->createMock(ColumnMapper::class);
		$this->columnMapper->method('findByUuid')->willThrowException(new DoesNotExistException(''));

		$this->formatPackService = $this->createMock(FormatPackService::class);
		$this->formatPackService->method('previewDefinitions')->willReturnCallback(fn (): array => $this->formatPreview);
		$this->formatPackService->method('importDefinitions')->willReturn(['created' => [], 'replaced' => [], 'kept' => [], 'unchanged' => [], 'skipped' => []]);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn (): bool => $this->isAdmin);

		$this->tableService = $tableService;
		$this->formatService = $formatService;
		$this->groupManager = $groupManager;
		$this->l10n = $l10n;
		$this->service = $this->createService();
	}

	private function createService(): TableSchemaService {
		$formatService = $this->formatService;
		$l10n = $this->l10n;
		return new TableSchemaService(
			$this->tableService,
			$this->columnService,
			$this->columnMapper,
			$formatService,
			$this->formatPackService,
			new TableSchemaExporter($formatService),
			new TableSchemaParser(new FormatPackReader(new FormatRuleEvaluator(), $this->createMock(FormatMapper::class), $l10n), $l10n),
			$this->groupManager,
			$this->createMock(IDBConnection::class),
			$this->createMock(IURLGenerator::class),
			$l10n,
		);
	}

	public function testCreatesATableWithEveryColumnOfTheSchema(): void {
		$result = $this->service->importAsNewTable(self::export(), [], 'alice');

		$this->assertCount(20, $this->importedColumns);
		$this->assertSame('nl.postcode', $this->importedColumns[6]['subtype']);
		$this->assertSame(['bsn'], $result['brokenColumns'], 'nl.bsn is installed nowhere, so the column keeps its subtype and is broken');
	}

	public function testGivesNewUuidsToColumnsWhoseUuidIsTaken(): void {
		$this->columnMapper = $this->createMock(ColumnMapper::class);
		$this->columnMapper->method('findByUuid')->willReturn(new Column());
		$this->service = $this->createService();

		$this->service->importAsNewTable(self::export(), [], 'alice');

		$this->assertSame([null], array_values(array_unique(array_column($this->importedColumns, 'uuid'))));
	}

	public function testAnAdministratorChoosesForEveryConflictingFormat(): void {
		$this->formatPreview = [['technicalName' => 'nl.postcode', 'status' => 'conflict']];
		$this->columnService->expects($this->never())->method('importColumn');

		try {
			$this->service->importAsNewTable(self::export(), [], 'admin');
			$this->fail('The import replaced or kept a conflicting format without a choice.');
		} catch (FormatImportConflictError $e) {
			$this->assertSame('nl.postcode', $e->conflicts[0]['technicalName']);
		}
	}

	public function testAnAdministratorsChoiceIsPassedOn(): void {
		$this->formatPreview = [['technicalName' => 'nl.postcode', 'status' => 'conflict']];
		$this->formatPackService->expects($this->once())->method('importDefinitions')
			->with($this->anything(), 'custom', ['nl.postcode' => 'replace'], 'admin');

		$this->service->importAsNewTable(self::export(), ['nl.postcode' => 'replace'], 'admin');
	}

	public function testSomeoneElseKeepsTheInstalledFormatAndIsWarned(): void {
		$this->isAdmin = false;
		$this->formatPreview = [['technicalName' => 'nl.postcode', 'status' => 'conflict'], ['technicalName' => 'email', 'status' => 'core']];
		$this->formatPackService->expects($this->once())->method('importDefinitions')
			->with($this->anything(), 'custom', ['nl.postcode' => 'keep'], 'alice');

		$result = $this->service->importAsNewTable(self::export(), ['nl.postcode' => 'replace'], 'alice');

		$this->assertStringContainsString('Ask an administrator', $result['warnings'][0]);
		$this->assertStringContainsString('Tables ships the format email', $result['warnings'][1]);
	}

	public function testAnExportOfTheTableItselfChangesNothing(): void {
		$changes = $this->service->previewChanges(7, self::export(), 'alice');

		$this->assertSame([], $changes['columns']['add']);
		$this->assertSame([], $changes['columns']['modify']);
		$this->assertSame([], $changes['columns']['remove']);
		$this->assertFalse($changes['hasChanges']);
	}

	public function testPreviewsAddedChangedAndRemovedColumns(): void {
		$schema = self::export();
		unset($schema['properties']['notes']);
		$schema['properties']['name']['title'] = 'Full name';
		$schema['properties']['name']['maxLength'] = 200;
		$schema['properties']['vat'] = ['type' => 'string', 'title' => 'VAT number'];

		$changes = $this->service->previewChanges(7, $schema, 'alice');

		$this->assertSame(['vat'], array_column($changes['columns']['add'], 'technicalName'));
		$this->assertSame(['notes'], array_column($changes['columns']['remove'], 'technicalName'));
		$this->assertSame('name', $changes['columns']['modify'][0]['technicalName']);
		$this->assertSame(['from' => 'Name', 'to' => 'Full name'], $changes['columns']['modify'][0]['changes']['title']);
		$this->assertSame(['from' => 100, 'to' => 200], $changes['columns']['modify'][0]['changes']['textMaxLength']);
		$this->assertTrue($changes['hasChanges']);
	}

	public function testRefusesToChangeTheTypeOfAColumn(): void {
		$schema = self::export();
		$schema['properties']['notes'] = ['type' => 'integer', 'x-tables-uuid' => $schema['properties']['notes']['x-tables-uuid']];

		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage('The property notes would change the column Notes from text to number');

		$this->service->previewChanges(7, $schema, 'alice');
	}

	public function testAnImportMayChangeAColumnToAFormatThatIsMissing(): void {
		$schema = self::export();
		$schema['properties']['notes']['x-tables-subtype'] = 'nl.notes';
		$this->columnService->expects($this->once())->method('update')
			->with(102, 'alice', $this->callback(static fn (ColumnDto $dto): bool => $dto->getSubtype() === 'nl.notes'), true);

		$result = $this->service->importIntoTable(7, $schema, [], 'alice');

		$this->assertContains('notes', $result['brokenColumns']);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function export(): array {
		return json_decode((string)file_get_contents(self::EXPORT_FILE), true, flags: JSON_THROW_ON_ERROR);
	}
}
