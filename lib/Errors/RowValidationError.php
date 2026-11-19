<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Errors;

/**
 * A row write that was refused, with one message per property that caused it.
 */
class RowValidationError extends BadRequestError {
	/**
	 * @param array<string, string> $errors the message per technical name
	 */
	public function __construct(
		public readonly array $errors,
	) {
		parent::__construct(count($errors) === 1 ? 'The row has 1 invalid value.' : 'The row has ' . count($errors) . ' invalid values.');
	}
}
