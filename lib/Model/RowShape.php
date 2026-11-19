<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Model;

use InvalidArgumentException;

/**
 * How a row travels over api/2: as cells keyed by column id, or as one object keyed by
 * the technical names of the columns, with the metadata in an `@self` block.
 */
enum RowShape: string {
	case Cells = 'cells';
	case Object = 'object';

	/**
	 * @throws InvalidArgumentException when the shape is not one of the known shapes
	 */
	public static function fromRequest(?string $shape): self {
		if ($shape === null || $shape === '') {
			return self::Cells;
		}
		return self::tryFrom($shape)
			?? throw new InvalidArgumentException('Unknown shape "' . $shape . '", use "cells" or "object".');
	}
}
