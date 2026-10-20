<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * Adds the format definitions that text and number subtypes can name. The core formats are
 * seeded by the SeedCoreFormats repair step.
 */
class Version2400Date20261020000000 extends SimpleMigrationStep {
	private const TABLE = 'tables_formats';
	private const INDEX_TECHNICAL_NAME = 'tables_formats_name_uniq';

	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE)) {
			return null;
		}

		$table = $schema->createTable(self::TABLE);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		$table->addColumn('technical_name', Types::STRING, [
			'notnull' => true,
			'length' => 64,
			'comment' => 'Name a column subtype refers to, unique across all formats',
		]);
		$table->addColumn('label', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('example', Types::STRING, [
			'notnull' => false,
			'default' => null,
			'length' => 255,
		]);
		$table->addColumn('type', Types::STRING, [
			'notnull' => true,
			'length' => 64,
			'comment' => 'Column type the format applies to',
		]);
		$table->addColumn('rule', Types::TEXT, [
			'notnull' => true,
			'comment' => 'JSON Logic rule that a value has to satisfy',
		]);
		$table->addColumn('enabled', Types::BOOLEAN, [
			'notnull' => false,
			'default' => true,
		]);
		$table->addColumn('version', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);
		$table->addColumn('created_by', Types::STRING, [
			'notnull' => false,
			'default' => null,
			'length' => 64,
			'comment' => 'Null for formats shipped with the app',
		]);
		$table->addColumn('updated_at', Types::DATETIME, [
			'notnull' => true,
		]);
		$table->addColumn('updated_by', Types::STRING, [
			'notnull' => false,
			'default' => null,
			'length' => 64,
		]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['technical_name'], self::INDEX_TECHNICAL_NAME);

		return $schema;
	}
}
