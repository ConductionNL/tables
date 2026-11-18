<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Model;

use InvalidArgumentException;

/**
 * A row written in the object shape: values keyed by the technical names of the columns.
 * An `@self` block, as the object shape returns it, is accepted and ignored.
 */
final class RowObjectInput {
	/**
	 * @param array<string, mixed> $values
	 * @throws InvalidArgumentException when a key is a number, which no technical name is, as in a list
	 */
	public function __construct(
		public readonly array $values,
	) {
		foreach (array_keys($values) as $key) {
			if (is_int($key)) {
				throw new InvalidArgumentException('Data in the object shape must be an object keyed by technical name.');
			}
		}
	}
}
