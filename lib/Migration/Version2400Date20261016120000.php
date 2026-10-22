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
 * Applications (contexts) get an optional technical name, which addresses them at
 * /apps/tables/app/{technicalName}. It is unique per owner, which the service checks.
 */
class Version2400Date20261016120000 extends SimpleMigrationStep {
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('tables_contexts_context')) {
			$table = $schema->getTable('tables_contexts_context');
			if (!$table->hasColumn('technical_name')) {
				$table->addColumn('technical_name', Types::STRING, [
					'notnull' => false,
					'default' => null,
					'length' => 200,
				]);
			}
		}

		return $schema;
	}
}
