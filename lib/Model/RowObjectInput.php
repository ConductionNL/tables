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
	 * @param array<array-key, mixed> $values
	 * @throws InvalidArgumentException when a property name is a number, as in a list; a
	 *                                  technical name starts with a letter, and the errors
	 *                                  of a row are keyed by property name, which keeps them
	 *                                  an object in JSON
	 */
	public function __construct(
		public readonly array $values,
	) {
		foreach (array_keys($values) as $name) {
			if (is_int($name)) {
				throw new InvalidArgumentException('Data in the object shape must be an object keyed by technical name, which starts with a letter.');
			}
		}
	}
}
