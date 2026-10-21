<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Errors;

/**
 * Removing a format that columns use, without having confirmed how many columns that breaks.
 */
class FormatInUseError extends \Exception {
	public function __construct(
		public readonly int $brokenColumnsCount,
	) {
		parent::__construct('Removing the format breaks ' . $brokenColumnsCount . ' columns.');
	}
}
