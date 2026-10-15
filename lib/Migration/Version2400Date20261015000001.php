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
 * Gives application pages a title and a technical name, and page content a widget
 * type, a configuration and a grid position. Content without a node is allowed.
 */
class Version2400Date20261015000001 extends SimpleMigrationStep {

	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('tables_contexts_page')) {
			$table = $schema->getTable('tables_contexts_page');
			foreach (['title', 'technical_name'] as $column) {
				if (!$table->hasColumn($column)) {
					$table->addColumn($column, Types::STRING, [
						'notnull' => false,
						'length' => 200,
					]);
					$changed = true;
				}
			}
		}

		if ($schema->hasTable('tables_contexts_page_content')) {
			$table = $schema->getTable('tables_contexts_page_content');
			if (!$table->hasColumn('type')) {
				$table->addColumn('type', Types::STRING, [
					'notnull' => true,
					'length' => 32,
					'default' => 'data',
				]);
				$changed = true;
			}
			if (!$table->hasColumn('configuration')) {
				$table->addColumn('configuration', Types::TEXT, [
					'notnull' => false,
				]);
				$changed = true;
			}
			foreach (['grid_x', 'grid_y', 'grid_width', 'grid_height'] as $column) {
				if (!$table->hasColumn($column)) {
					$table->addColumn($column, Types::INTEGER, [
						'notnull' => false,
					]);
					$changed = true;
				}
			}
			$nodeRelId = $table->getColumn('node_rel_id');
			if ($nodeRelId->getNotnull()) {
				$nodeRelId->setNotnull(false);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
