<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\JsonSchema;

use OCA\Tables\Db\Column;
use OCA\Tables\Db\Format;
use OCA\Tables\Db\Table;

/**
 * A table with a column of every type and subtype, and the formats it uses, for the exported
 * schema in tests/formats/table-schema-export.json.
 */
final class SchemaFixtures {
	public const SCHEMA_URL = 'https://cloud.example.org/ocs/v2.php/apps/tables/api/2/tables/7/schema';

	public static function table(): Table {
		$table = new Table();
		$table->setId(7);
		$table->setUuid('0192a6e4-0000-7000-8000-000000000007');
		$table->setTitle('Customers');
		$table->setDescription('Everyone we work for');
		$table->setEmoji('🏢');
		return $table;
	}

	/**
	 * @return array<string, Format> the installed formats by technical name; nl.bsn is not installed
	 */
	public static function formats(): array {
		return [
			'email' => self::format('email', 'Email address', 'jane@example.org', 'core', '{"match":[{"var":"value"},"^[^@ ]+@[^@ ]+$"]}'),
			'url' => self::format('url', 'URL', 'https://example.org/path', 'core', '{"match":[{"var":"value"},"^[a-z]+:.+$"]}'),
			'nl.postcode' => self::format('nl.postcode', 'Postal code (NL)', '1234 AB', 'pack:nl', '{"match":[{"var":"value"},"^[1-9][0-9]{3} ?[A-Z]{2}$"]}'),
		];
	}

	/**
	 * @return list<Column>
	 */
	public static function columns(): array {
		$id = 100;
		$column = static function (string $technicalName, string $title, string $type, string $subtype, array $settings = []) use (&$id): Column {
			$column = new Column();
			$column->setId(++$id);
			$column->setUuid(sprintf('0192a6e4-0000-7000-8000-%012d', $id));
			$column->setTechnicalName($technicalName);
			$column->setTitle($title);
			$column->setType($type);
			$column->setSubtype($subtype);
			$column->setMandatory(false);
			$column->setDescription('');
			foreach ($settings as $setter => $value) {
				$column->{'set' . ucfirst($setter)}($value);
			}
			return $column;
		};

		$columns = [
			$column('name', 'Name', 'text', 'line', ['mandatory' => true, 'textMaxLength' => 100, 'description' => 'Full name']),
			$column('notes', 'Notes', 'text', 'long'),
			$column('profile', 'Profile', 'text', 'rich'),
			$column('website', 'Website', 'text', 'link', ['textAllowedPattern' => 'url,files']),
			$column('email', 'Email', 'text', 'email', ['mandatory' => true, 'textUnique' => true]),
			$column('homepage', 'Homepage', 'text', 'url'),
			$column('postcode', 'Postcode', 'text', 'nl.postcode', ['textAllowedPattern' => '^[1-4]']),
			$column('bsn', 'BSN', 'text', 'nl.bsn'),
			$column('revenue', 'Revenue', 'number', '', ['numberDecimals' => 2, 'numberMin' => 0.0, 'numberPrefix' => '€']),
			$column('employees', 'Employees', 'number', '', ['numberDecimals' => 0, 'numberDefault' => 1.0]),
			$column('rating', 'Rating', 'number', 'stars'),
			$column('progress', 'Progress', 'number', 'progress', ['numberSuffix' => '%']),
			$column('status', 'Status', 'selection', '', ['selectionOptions' => json_encode([['id' => 1, 'label' => 'Lead'], ['id' => 2, 'label' => 'Customer']]), 'selectionDefault' => '1']),
			$column('sectors', 'Sectors', 'selection', 'multi', ['selectionOptions' => json_encode([['id' => 1, 'label' => 'Government'], ['id' => 2, 'label' => 'Education']]), 'selectionDefault' => '[2]']),
			$column('active', 'Active', 'selection', 'check', ['selectionDefault' => 'true']),
			$column('last_contact', 'Last contact', 'datetime', ''),
			$column('since', 'Customer since', 'datetime', 'date', ['datetimeDefault' => 'today']),
			$column('call_at', 'Call at', 'datetime', 'time'),
			$column('owner', 'Owner', 'usergroup', '', ['usergroupMultipleItems' => false, 'usergroupSelectUsers' => true, 'usergroupSelectGroups' => false, 'usergroupSelectTeams' => false, 'showUserStatus' => true, 'usergroupDefault' => '[]']),
			$column('contact', 'Contact person', 'relation', '', ['customSettings' => json_encode(['relationType' => 'table', 'targetId' => 3, 'labelColumn' => 12, 'width' => 200])]),
		];
		$columns[7]->setBroken(true);
		return $columns;
	}

	private static function format(string $technicalName, string $label, string $example, string $source, string $rule): Format {
		$format = new Format();
		$format->setTechnicalName($technicalName);
		$format->setLabel($label);
		$format->setExample($example);
		$format->setType('text');
		$format->setSource($source);
		$format->setRule($rule);
		$format->setVersion('1.0.0');
		$format->setEnabled(true);
		return $format;
	}
}
