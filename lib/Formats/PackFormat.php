<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Formats;

/**
 * A format as a pack defines it, with any sameAs already replaced by a copy of the rule it names.
 */
final class PackFormat {
	/**
	 * @param array<string, mixed> $rule
	 */
	public function __construct(
		public readonly string $technicalName,
		public readonly string $label,
		public readonly ?string $example,
		public readonly string $type,
		public readonly string $version,
		public readonly array $rule,
	) {
	}
}
