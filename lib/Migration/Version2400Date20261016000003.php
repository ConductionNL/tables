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
 * Requires a technical name on every application page, unique within its
 * application. Runs after {@see Version2400Date20261016000002} filled them in.
 */
class Version2400Date20261016000003 extends SimpleMigrationStep {
	private const INDEX = 'tables_ctx_page_name_uq';

	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('tables_contexts_page')) {
			return null;
		}

		$table = $schema->getTable('tables_contexts_page');
		$changed = false;
		$technicalName = $table->getColumn('technical_name');
		if (!$technicalName->getNotnull()) {
			$technicalName->setNotnull(true);
			$changed = true;
		}
		if (!$table->hasIndex(self::INDEX)) {
			$table->addUniqueIndex(['context_id', 'technical_name'], self::INDEX);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
