<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\JsonSchema;

use OCA\Tables\Formats\PackFormat;

/**
 * A JSON Schema read as a table: its columns in the shape ColumnService::importColumn() takes, and
 * the format definitions the schema carries for them.
 */
final class ParsedTableSchema {
	/**
	 * @param list<array<string, mixed>> $columns
	 * @param array<string, PackFormat> $formatDefinitions by technical name
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $description,
		public readonly ?string $emoji,
		public readonly array $columns,
		public readonly array $formatDefinitions,
	) {
	}
}
