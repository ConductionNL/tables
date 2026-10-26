<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\JsonSchema;

use OCA\Tables\Db\Column;
use OCA\Tables\Db\FormatMapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Formats\FormatPackReader;
use OCA\Tables\Formats\FormatRuleEvaluator;
use OCA\Tables\JsonSchema\TableSchemaParser;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class TableSchemaParserTest extends TestCase {
	private const EXPORT_FILE = __DIR__ . '/../../formats/table-schema-export.json';

	private TableSchemaParser $parser;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$reader = new FormatPackReader(new FormatRuleEvaluator(), $this->createMock(FormatMapper::class), $l10n);
		$this->parser = new TableSchemaParser($reader, $l10n);
	}

	public function testAnExportImportsBackAsTheSameColumns(): void {
		$parsed = $this->parser->parse(json_decode((string)file_get_contents(self::EXPORT_FILE), true, flags: JSON_THROW_ON_ERROR));

		$this->assertSame('Customers', $parsed->title);
		$this->assertSame('🏢', $parsed->emoji);
		$imported = array_column($parsed->columns, null, 'technicalName');
		foreach (SchemaFixtures::columns() as $original) {
			$column = $imported[$original->getTechnicalName()];
			$this->assertSame([
				$original->getTitle(), $original->getType(), $original->getSubtype(), (bool)$original->getMandatory(), $original->getUuid(),
			], [
				$column['title'], $column['type'], $column['subtype'], $column['mandatory'], $column['uuid'],
			], $original->getTechnicalName());
			$this->assertSame($original->getCustomSettingsArray(), $column['customSettings'], $original->getTechnicalName());
		}

		$this->assertSame(100, $imported['name']['textMaxLength']);
		$this->assertSame('Full name', $imported['name']['description']);
		$this->assertSame('url,files', $imported['website']['textAllowedPattern']);
		$this->assertSame('^[1-4]', $imported['postcode']['textAllowedPattern']);
		$this->assertTrue($imported['email']['textUnique']);
		$this->assertSame([2, '€', 0.0], [$imported['revenue']['numberDecimals'], $imported['revenue']['numberPrefix'], $imported['revenue']['numberMin']]);
		$this->assertSame([0, 1.0], [$imported['employees']['numberDecimals'], $imported['employees']['numberDefault']]);
		$this->assertNull($imported['rating']['numberMax'], 'stars keep their own range');
		$this->assertSame([['id' => 1, 'label' => 'Lead'], ['id' => 2, 'label' => 'Customer']], $imported['status']['selectionOptions']);
		$this->assertSame('1', $imported['status']['selectionDefault']);
		$this->assertSame('[2]', $imported['sectors']['selectionDefault']);
		$this->assertSame('true', $imported['active']['selectionDefault']);
		$this->assertSame('today', $imported['since']['datetimeDefault']);
		$this->assertSame([false, true, true], [$imported['owner']['usergroupMultipleItems'], $imported['owner']['usergroupSelectUsers'], $imported['owner']['showUserStatus']]);
	}

	public function testAnExportCarriesThePackFormatItUses(): void {
		$parsed = $this->parser->parse(json_decode((string)file_get_contents(self::EXPORT_FILE), true, flags: JSON_THROW_ON_ERROR));

		$this->assertSame(['nl.postcode'], array_keys($parsed->formatDefinitions));
		$definition = $parsed->formatDefinitions['nl.postcode'];
		$this->assertSame(['Postal code (NL)', '1234 AB', '1.0.0'], [$definition->label, $definition->example, $definition->version]);
	}

	public function testMapsTheStandardKeywordsOfASchemaFromAnotherTool(): void {
		$parsed = $this->parser->parse([
			'$schema' => 'http://json-schema.org/draft-07/schema#',
			'title' => 'Contacts',
			'type' => 'object',
			'required' => ['fullName'],
			'properties' => [
				'fullName' => ['type' => 'string', 'maxLength' => 80],
				'homepage' => ['type' => 'string', 'format' => 'uri'],
				'mail' => ['type' => ['string', 'null'], 'format' => 'email'],
				'host' => ['type' => 'string', 'format' => 'hostname'],
				'bio' => ['type' => 'string', 'contentMediaType' => 'text/html'],
				'age' => ['type' => 'integer', 'minimum' => 0],
				'score' => ['type' => 'number'],
				'vip' => ['type' => 'boolean', 'default' => false],
				'level' => ['type' => 'string', 'enum' => ['bronze', 'silver', 'gold'], 'default' => 'silver'],
				'tags' => ['type' => 'array', 'items' => ['enum' => ['a', 'b']]],
				'born' => ['type' => 'string', 'format' => 'date'],
				'seen' => ['type' => 'string', 'format' => 'date-time'],
			],
		]);

		$columns = array_column($parsed->columns, null, 'technicalName');
		$this->assertSame(['full_name', 'homepage', 'mail', 'host', 'bio', 'age', 'score', 'vip', 'level', 'tags', 'born', 'seen'], array_keys($columns));
		$typeOf = static fn (string $name): string => $columns[$name]['type'] . '/' . $columns[$name]['subtype'];
		$this->assertSame('text/line', $typeOf('full_name'));
		$this->assertTrue($columns['full_name']['mandatory']);
		$this->assertSame('text/uri', $typeOf('homepage'), 'a uri is a format, not a link');
		$this->assertSame('text/email', $typeOf('mail'));
		$this->assertSame('text/hostname', $typeOf('host'), 'an unknown format becomes the subtype');
		$this->assertSame('text/rich', $typeOf('bio'));
		$this->assertSame(['number/', 0, 0.0], [$typeOf('age'), $columns['age']['numberDecimals'], $columns['age']['numberMin']]);
		$this->assertSame(2, $columns['score']['numberDecimals']);
		$this->assertSame(['selection/check', 'false'], [$typeOf('vip'), $columns['vip']['selectionDefault']]);
		$this->assertSame(['selection/', '2'], [$typeOf('level'), $columns['level']['selectionDefault']]);
		$this->assertSame([['id' => 1, 'label' => 'a'], ['id' => 2, 'label' => 'b']], $columns['tags']['selectionOptions']);
		$this->assertSame('selection/multi', $typeOf('tags'));
		$this->assertSame('datetime/date', $typeOf('born'));
		$this->assertSame('datetime/', $typeOf('seen'));
	}

	/**
	 * @dataProvider refusedSchemas
	 * @param array<string, mixed> $property
	 */
	public function testRefusesWhatCannotBeImportedNamingTheProperty(array $property, string $message): void {
		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage($message);

		$this->parser->parse(['type' => 'object', 'properties' => ['address' => $property]]);
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function refusedSchemas(): array {
		$definition = ['technicalName' => 'xx.code', 'label' => 'Code', 'type' => 'text', 'version' => '1.0.0', 'example' => '1234'];
		return [
			'a nested object' => [['type' => 'object', 'properties' => ['street' => ['type' => 'string']]], 'The property address is a nested object'],
			'a list of objects' => [['type' => 'array', 'items' => ['type' => 'object']], 'The property address is a list'],
			'an unknown subtype of a selection' => [['x-tables-type' => 'selection', 'x-tables-subtype' => 'tree'], 'The property address has the subtype tree, which columns of type selection do not have.'],
			'an unknown subtype of a datetime' => [['x-tables-type' => 'datetime', 'x-tables-subtype' => 'week'], 'columns of type datetime do not have'],
			'an unknown subtype of a usergroup' => [['x-tables-type' => 'usergroup', 'x-tables-subtype' => 'team'], 'columns of type usergroup do not have'],
			'a definition with a regex JavaScript cannot run' => [
				['type' => 'string', 'x-tables-type' => 'text', 'x-tables-subtype' => 'xx.code', 'x-tables-format-definition' => [...$definition, 'rule' => ['match' => [['var' => 'value'], '^[0-9]++$']]]],
				'The rule of the format xx.code cannot be used',
			],
			'a definition for another subtype' => [
				['type' => 'string', 'x-tables-type' => 'text', 'x-tables-subtype' => 'yy.code', 'x-tables-format-definition' => [...$definition, 'rule' => ['match' => [['var' => 'value'], '^[0-9]+$']]]],
				'The format definition of the property address is not for its subtype.',
			],
		];
	}

	public function testRefusesAnotherDialect(): void {
		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage('Only JSON Schema 2020-12 and draft-07 can be imported.');

		$this->parser->parse(['$schema' => 'http://json-schema.org/draft-04/schema#', 'type' => 'object', 'properties' => ['a' => ['type' => 'string']]]);
	}

	public function testKeepsAnUnknownFormatOfATextColumnAsItsSubtype(): void {
		$parsed = $this->parser->parse(['type' => 'object', 'properties' => ['bsn' => ['type' => 'string', 'x-tables-type' => 'text', 'x-tables-subtype' => 'nl.bsn', 'format' => 'nl.bsn', 'x-tables-broken' => true]]]);

		$this->assertSame(Column::TYPE_TEXT, $parsed->columns[0]['type']);
		$this->assertSame('nl.bsn', $parsed->columns[0]['subtype']);
		$this->assertSame([], $parsed->formatDefinitions);
	}
}
