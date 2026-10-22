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
 * Drops tables_contexts_widget_setting. It runs after Version2400Date20261022000001
 * has copied its rows into tables_contexts_widget.settings: a step applies its
 * changeSchema before its own postSchemaChange, so the copy and the drop cannot share
 * one step.
 */
class Version2400Date20261022000002 extends SimpleMigrationStep {
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(Version2400Date20261022000001::SETTING_TABLE)) {
			return null;
		}
		$schema->dropTable(Version2400Date20261022000001::SETTING_TABLE);
		return $schema;
	}
}
