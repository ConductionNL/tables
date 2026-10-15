<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Helper;

use OCA\Tables\Service\GridWidgetTypes;

/**
 * Reads the content rows of an application page as grid widgets.
 *
 * A row without a type is a data widget, a row without a configuration gets the
 * defaults of its type, and rows without a position are placed full width below
 * the lowest positioned row, stacked by their order.
 */
class PageContentHelper {
	public const UNPLACED_WIDTH = GridWidgetTypes::GRID_COLUMNS;
	public const UNPLACED_HEIGHT = 5;

	private const POSITION_KEYS = ['grid_x', 'grid_y', 'grid_width', 'grid_height'];

	/**
	 * Applies {@see self::readContent()} to the content of every page.
	 *
	 * @param array<array-key, array<string, mixed>> $pages
	 * @return array<array-key, array<string, mixed>>
	 */
	public static function readPages(array $pages): array {
		foreach ($pages as $key => $page) {
			if (is_array($page['content'] ?? null)) {
				$pages[$key]['content'] = self::readContent($page['content']);
			}
		}
		return $pages;
	}

	/**
	 * Returns the content of one page, keyed as given, with every entry carrying
	 * id, order, node_rel_id, type, configuration, content and a grid position.
	 * Other keys an entry already has are kept.
	 *
	 * @param array<array-key, array<string, mixed>> $content entries shaped like the context
	 *                                                        mapper output or PageContent::jsonSerialize()
	 * @return array<array-key, array<string, mixed>>
	 */
	public static function readContent(array $content): array {
		$entries = [];
		$bottom = 0;
		foreach ($content as $key => $entry) {
			$entry = self::readEntry($key, $entry);
			if (self::hasPosition($entry)) {
				$bottom = max($bottom, $entry['grid_y'] + $entry['grid_height']);
			}
			$entries[$key] = $entry;
		}

		$unplaced = array_keys(array_filter($entries, static fn (array $entry): bool => !self::hasPosition($entry)));
		usort($unplaced, static fn ($a, $b): int => [$entries[$a]['order'], $entries[$a]['id']] <=> [$entries[$b]['order'], $entries[$b]['id']]);
		foreach ($unplaced as $key) {
			$entries[$key]['grid_x'] = 0;
			$entries[$key]['grid_y'] = $bottom;
			$entries[$key]['grid_width'] = self::UNPLACED_WIDTH;
			$entries[$key]['grid_height'] = self::UNPLACED_HEIGHT;
			$bottom += self::UNPLACED_HEIGHT;
		}

		return $entries;
	}

	/**
	 * The order every item gets from its position: top to bottom, then left to right.
	 * Items keep their keys; the first gets 10, the next 20 and so on.
	 *
	 * @param array<array-key, array{grid_x: int, grid_y: int, ...}> $items
	 * @return array<array-key, int>
	 */
	public static function orderFromPositions(array $items): array {
		$keys = array_keys($items);
		usort($keys, static fn ($a, $b): int => [$items[$a]['grid_y'], $items[$a]['grid_x']] <=> [$items[$b]['grid_y'], $items[$b]['grid_x']]);
		$order = [];
		foreach ($keys as $index => $key) {
			$order[$key] = ($index + 1) * 10;
		}
		return $order;
	}

	/**
	 * Decodes a stored configuration into its configuration and content, each
	 * filled up with the defaults of the widget type.
	 *
	 * @return array{configuration: array<string, mixed>, content: array<string, mixed>}
	 */
	public static function readConfiguration(string $type, mixed $stored): array {
		$defaults = GridWidgetTypes::defaults($type);
		if (is_string($stored)) {
			$stored = json_decode($stored, true);
		}
		if (!is_array($stored)) {
			return $defaults;
		}
		return [
			'configuration' => array_merge($defaults['configuration'], is_array($stored['configuration'] ?? null) ? $stored['configuration'] : []),
			'content' => array_merge($defaults['content'], is_array($stored['content'] ?? null) ? $stored['content'] : []),
		];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	private static function readEntry(int|string $key, array $entry): array {
		$type = $entry['type'] ?? null;
		$type = is_string($type) && $type !== '' ? $type : GridWidgetTypes::TYPE_DATA;
		$nodeRelId = $entry['node_rel_id'] ?? $entry['nodeRelId'] ?? null;

		$entry['id'] = (int)($entry['id'] ?? $key);
		$entry['order'] = (int)($entry['order'] ?? 0);
		$entry['node_rel_id'] = $nodeRelId === null ? null : (int)$nodeRelId;
		$entry['type'] = $type;
		$entry = array_merge($entry, self::readConfiguration($type, $entry['configuration'] ?? null));

		$position = [
			'grid_x' => $entry['grid_x'] ?? $entry['gridX'] ?? null,
			'grid_y' => $entry['grid_y'] ?? $entry['gridY'] ?? null,
			'grid_width' => $entry['grid_width'] ?? $entry['gridWidth'] ?? null,
			'grid_height' => $entry['grid_height'] ?? $entry['gridHeight'] ?? null,
		];
		unset($entry['gridX'], $entry['gridY'], $entry['gridWidth'], $entry['gridHeight']);
		foreach ($position as $name => $value) {
			$entry[$name] = $value === null ? null : (int)$value;
		}
		return $entry;
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function hasPosition(array $entry): bool {
		foreach (self::POSITION_KEYS as $name) {
			if ($entry[$name] === null) {
				return false;
			}
		}
		return true;
	}
}
