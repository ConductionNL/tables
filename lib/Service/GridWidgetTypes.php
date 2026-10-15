<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

/**
 * The widget types a page can hold, with the size, configuration and content
 * a widget of each type starts with.
 *
 * A data widget shows the node its page content row points at, so it has no
 * content of its own.
 *
 * @psalm-type TablesGridWidgetValue = string|bool|int|null
 */
class GridWidgetTypes {
	public const TYPE_HEADER = 'header';
	public const TYPE_TEXT = 'text';
	public const TYPE_DATA = 'data';

	public const GRID_COLUMNS = 12;

	/**
	 * Default width, height, configuration and content of every type.
	 */
	private const DEFAULTS = [
		self::TYPE_HEADER => [
			'width' => 12,
			'height' => 2,
			'configuration' => ['title' => '', 'showTitle' => false],
			'content' => ['title' => '', 'subtitle' => '', 'textAlign' => 'left', 'backgroundColor' => '', 'textColor' => ''],
		],
		self::TYPE_TEXT => [
			'width' => 6,
			'height' => 2,
			'configuration' => ['title' => '', 'showTitle' => true],
			'content' => ['text' => ''],
		],
		self::TYPE_DATA => [
			'width' => 12,
			'height' => 5,
			'configuration' => ['title' => '', 'showTitle' => false],
			'content' => [],
		],
	];

	/**
	 * The configuration and content a widget of the given type starts with.
	 * An unknown type has none.
	 *
	 * @return array{configuration: array<string, TablesGridWidgetValue>, content: array<string, TablesGridWidgetValue>}
	 */
	public static function defaults(string $type): array {
		return [
			'configuration' => self::DEFAULTS[$type]['configuration'] ?? [],
			'content' => self::DEFAULTS[$type]['content'] ?? [],
		];
	}
}
