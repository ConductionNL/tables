<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Model;

/**
 * A row written in the object shape: values keyed by the technical names of the columns.
 * An `@self` block, as the object shape returns it, is accepted and ignored.
 */
final class RowObjectInput {
	/**
	 * @param array<string, mixed> $values
	 */
	public function __construct(
		public readonly array $values,
	) {
	}
}
