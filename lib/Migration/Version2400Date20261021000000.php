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
 * Records where a format comes from: core for the formats Tables ships, pack:{name} for formats
 * installed from a format pack and custom for formats an administrator defines. The
 * SeedCoreFormats repair step marks the core formats that were installed before.
 */
class Version2400Date20261021000000 extends SimpleMigrationStep {
	private const TABLE = 'tables_formats';
	private const COL_SOURCE = 'source';

	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(self::TABLE)) {
			return null;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn(self::COL_SOURCE)) {
			return null;
		}
		$table->addColumn(self::COL_SOURCE, Types::STRING, [
			'notnull' => true,
			'default' => 'custom',
			'length' => 64,
			'comment' => 'core, pack:{name} or custom',
		]);

		return $schema;
	}

}
