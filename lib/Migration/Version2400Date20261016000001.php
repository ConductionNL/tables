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
 * Grid pages: a title, emoji and description on pages, the widgets of a page with
 * their settings as one JSON object, and the node a data widget shows.
 */
class Version2400Date20261016000001 extends SimpleMigrationStep {
	private const PREFIX = 'tables_contexts_';

	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->extendPageTable($schema);
		$this->haveWidgetTable($schema);
		$this->haveWidgetSourceTable($schema);

		return $schema;
	}

	private function extendPageTable(ISchemaWrapper $schema): void {
		if (!$schema->hasTable(self::PREFIX . 'page')) {
			return;
		}
		$table = $schema->getTable(self::PREFIX . 'page');
		if (!$table->hasColumn('title')) {
			$table->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 200]);
		}
		if (!$table->hasColumn('emoji')) {
			$table->addColumn('emoji', Types::STRING, ['notnull' => false, 'length' => 64]);
		}
		if (!$table->hasColumn('description')) {
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
		}
		if (!$table->hasIndex('tables_ctx_page_ctx_idx')) {
			$table->addIndex(['context_id'], 'tables_ctx_page_ctx_idx');
		}
	}

	private function haveWidgetTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable(self::PREFIX . 'widget')) {
			return;
		}
		$table = $schema->createTable(self::PREFIX . 'widget');
		$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('page_id', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 200]);
		$table->addColumn('grid_x', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('grid_y', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->addColumn('grid_width', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
		$table->addColumn('grid_height', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
		$table->addColumn('settings', Types::TEXT, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['page_id'], 'tables_ctx_widget_page_idx');
	}

	private function haveWidgetSourceTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable(self::PREFIX . 'widget_source')) {
			return;
		}
		$table = $schema->createTable(self::PREFIX . 'widget_source');
		$table->addColumn('widget_id', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('node_rel_id', Types::INTEGER, ['notnull' => true]);
		$table->setPrimaryKey(['widget_id']);
		$table->addIndex(['node_rel_id'], 'tables_ctx_wsource_node_idx');
	}
}
