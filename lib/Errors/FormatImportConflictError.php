<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Errors;

use OCA\Tables\ResponseDefinitions;

/**
 * An import that would change installed formats without a choice to keep or replace each of them.
 *
 * @psalm-import-type TablesFormatPackItem from ResponseDefinitions
 */
class FormatImportConflictError extends \Exception {
	/**
	 * @param list<TablesFormatPackItem> $conflicts
	 */
	public function __construct(
		public readonly array $conflicts,
	) {
		parent::__construct('The import changes installed formats: ' . implode(', ', array_column($conflicts, 'technicalName')) . '.');
	}
}
