<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\JsonSchema;

use OCA\Tables\JsonSchema\TableSchemaExporter;
use OCA\Tables\Service\FormatService;
use PHPUnit\Framework\TestCase;

/**
 * The export of the fixture table is the file tests/frontend/tableSchema.test.mjs validates against
 * the JSON Schema 2020-12 meta-schema.
 */
class TableSchemaExporterTest extends TestCase {
	private const EXPORT_FILE = __DIR__ . '/../../formats/table-schema-export.json';

	/** @var array<string, mixed> */
	private array $export;

	protected function setUp(): void {
		$formats = SchemaFixtures::formats();
		$formatService = $this->createMock(FormatService::class);
		$formatService->method('findUsableFormat')->willReturnCallback(static fn (string $technicalName): mixed => $formats[$technicalName] ?? null);
		$exporter = new TableSchemaExporter($formatService);
		$this->export = json_decode(json_encode($exporter->export(SchemaFixtures::table(), SchemaFixtures::columns(), SchemaFixtures::SCHEMA_URL)), true);
	}

	public function testExportsTheFixtureTableAsCommitted(): void {
		$committed = json_decode((string)file_get_contents(self::EXPORT_FILE), true, flags: JSON_THROW_ON_ERROR);

		$this->assertSame($committed, $this->export, 'Update tests/formats/table-schema-export.json when the export changes on purpose.');
	}

	public function testDescribesTheTableAsAnObjectWithOnePropertyPerColumn(): void {
		$this->assertSame('https://json-schema.org/draft/2020-12/schema', $this->export['$schema']);
		$this->assertSame('object', $this->export['type']);
		$this->assertCount(20, $this->export['properties']);
		$this->assertSame(['name', 'email'], $this->export['required']);
	}

	public function testAUrlColumnExportsAsUriAndKeepsItsSubtype(): void {
		$this->assertSame('uri', $this->export['properties']['homepage']['format']);
		$this->assertSame('url', $this->export['properties']['homepage']['x-tables-subtype']);
	}

	public function testAPackFormatTravelsWithItsDefinitionAndExample(): void {
		$postcode = $this->export['properties']['postcode'];

		$this->assertArrayNotHasKey('format', $postcode);
		$this->assertSame(['1234 AB'], $postcode['examples']);
		$this->assertSame('nl.postcode', $postcode['x-tables-format-definition']['technicalName']);
		$this->assertSame('^[1-9][0-9]{3} ?[A-Z]{2}$', $postcode['x-tables-format-definition']['rule']['match'][1]);
		$this->assertSame('^[1-4]', $postcode['pattern']);
	}

	public function testACoreFormatTravelsWithoutItsDefinition(): void {
		$this->assertArrayNotHasKey('x-tables-format-definition', $this->export['properties']['email']);
		$this->assertSame(['jane@example.org'], $this->export['properties']['email']['examples']);
	}

	public function testABrokenColumnExportsItsSubtypeAsFormat(): void {
		$bsn = $this->export['properties']['bsn'];

		$this->assertSame('nl.bsn', $bsn['format']);
		$this->assertTrue($bsn['x-tables-broken']);
		$this->assertArrayNotHasKey('x-tables-format-definition', $bsn);
	}

	public function testSelectionsExportTheirOptionIdsAndLabels(): void {
		$status = $this->export['properties']['status'];

		$this->assertSame([1, 2], $status['enum']);
		$this->assertSame([['id' => 1, 'label' => 'Lead'], ['id' => 2, 'label' => 'Customer']], $status['x-tables-options']);
		$this->assertSame([1, 2], $this->export['properties']['sectors']['items']['enum']);
		$this->assertSame('boolean', $this->export['properties']['active']['type']);
	}

	public function testANumberWithoutDecimalsIsAnInteger(): void {
		$this->assertSame('integer', $this->export['properties']['employees']['type']);
		$this->assertSame('number', $this->export['properties']['revenue']['type']);
	}
}
