<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * Makes the technical names of applications and their nodes NOT NULL and unique,
 * after Version2500Date20261026000000 filled them in. An application's name is
 * unique for its owner, a node's name within its application.
 */
class Version2500Date20261026000001 extends SimpleMigrationStep {
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;
		$uniqueIndexes = [
			'tables_contexts_context' => [['owner_type', 'owner_id', 'technical_name'], 'tables_ctx_owner_tech_name_uq'],
			'tables_contexts_rel_context_node' => [['context_id', 'technical_name'], 'tables_ctx_node_tech_name_uq'],
		];
		foreach ($uniqueIndexes as $tableName => [$columns, $indexName]) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$table = $schema->getTable($tableName);
			$technicalName = $table->getColumn('technical_name');
			if (!$technicalName->getNotnull()) {
				$technicalName->setNotnull(true);
				$changed = true;
			}
			if (!$table->hasIndex($indexName)) {
				$table->addUniqueIndex($columns, $indexName);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
