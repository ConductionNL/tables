<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Formats;

use OCA\Tables\Db\Format;

/**
 * A set of formats installed and removed together, such as the formats of a country.
 */
final class FormatPack {
	/**
	 * @param array<string, PackFormat> $formats by technical name
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $label,
		public readonly string $version,
		public readonly array $formats,
	) {
	}

	/**
	 * The source of the formats installed from this pack.
	 */
	public function source(): string {
		return Format::SOURCE_PACK_PREFIX . $this->name;
	}
}
