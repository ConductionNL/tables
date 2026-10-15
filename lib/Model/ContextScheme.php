<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Model;

use DateTime;
use InvalidArgumentException;
use JsonSerializable;

class ContextScheme implements JsonSerializable {

	public function __construct(
		protected ?string $name,
		protected ?string $icon,
		protected ?string $description,
		protected ?array $nodes = [],
		protected ?array $pages = [],
		protected ?array $tables = [],
		protected ?array $menuItems = [],
		protected array $gridViews = [],
	) {
	}

	/**
	 * Builds the scheme from an import request and refuses what the import could not handle.
	 *
	 * @param array<string, mixed> $data
	 * @throws InvalidArgumentException
	 */
	public static function createFromInputArray(array $data): self {
		$tables = $data['tables'] ?? null;
		if (!is_array($tables) || !isset($tables['addTables'], $tables['modifyTables']) || !is_array($tables['addTables']) || !is_array($tables['modifyTables'])) {
			throw new InvalidArgumentException('Invalid tables structure provided: expected addTables and modifyTables lists.');
		}
		foreach (['nodes', 'menuItems', 'gridViews'] as $list) {
			if (isset($data[$list]) && !is_array($data[$list])) {
				throw new InvalidArgumentException('The scheme property "' . $list . '" must be a list.');
			}
		}
		if (!is_string($data['name'] ?? null) || trim($data['name']) === '') {
			throw new InvalidArgumentException('A scheme needs a name.');
		}
		foreach (['icon', 'description'] as $optional) {
			if (isset($data[$optional]) && !is_string($data[$optional])) {
				throw new InvalidArgumentException('The scheme property "' . $optional . '" must be a string.');
			}
		}
		self::assertGridViewsValid($data['gridViews'] ?? []);
		return new self(
			name: $data['name'],
			icon: $data['icon'] ?? null,
			description: $data['description'] ?? null,
			nodes: array_values($data['nodes'] ?? []),
			pages: [],
			tables: $tables,
			// A scheme without a menu leaves the application's menu alone; an empty one clears it
			menuItems: isset($data['menuItems']) ? array_values($data['menuItems']) : null,
			gridViews: array_values($data['gridViews'] ?? []),
		);
	}

	/**
	 * Every view without a table in a scheme needs a uuid and a title; its other fields are text or absent,
	 * and its dates are in the format the export writes.
	 *
	 * @throws InvalidArgumentException
	 */
	private static function assertGridViewsValid(array $gridViews): void {
		foreach ($gridViews as $gridView) {
			if (!is_array($gridView)) {
				throw new InvalidArgumentException('Every entry of "gridViews" must be an object.');
			}
			foreach (['uuid', 'title'] as $required) {
				if (!is_string($gridView[$required] ?? null) || trim($gridView[$required]) === '') {
					throw new InvalidArgumentException('Every entry of "gridViews" needs a "' . $required . '".');
				}
			}
			foreach (['emoji', 'description', 'createdAt', 'lastEditAt'] as $optional) {
				if (isset($gridView[$optional]) && !is_string($gridView[$optional])) {
					throw new InvalidArgumentException('The grid view property "' . $optional . '" must be a string.');
				}
			}
			foreach (['createdAt', 'lastEditAt'] as $date) {
				if (isset($gridView[$date]) && DateTime::createFromFormat('Y-m-d H:i:s', $gridView[$date]) === false) {
					throw new InvalidArgumentException('The grid view property "' . $date . '" must be a date like 2026-10-14 12:00:00.');
				}
			}
			if (isset($gridView['grid']) && !is_array($gridView['grid'])) {
				throw new InvalidArgumentException('The grid view property "grid" must be an object.');
			}
		}
	}

	public function getName(): ?string {
		return $this->name ?? '';
	}

	public function getIcon(): ?string {
		return $this->icon;
	}

	public function getDescription(): ?string {
		return $this->description;
	}

	public function getNodes(): ?array {
		return $this->nodes;
	}

	public function getPages(): ?array {
		return $this->pages;
	}

	public function getTables(): ?array {
		return $this->tables;
	}

	/**
	 * Menu items point at their target by uuid, so a scheme can be imported on another instance.
	 * Null when the scheme has no menu, which leaves the application's menu as it is.
	 */
	public function getMenuItems(): ?array {
		return $this->menuItems;
	}

	/**
	 * Views without a table that the menu points at; they live outside the tables of the scheme.
	 */
	public function getGridViews(): array {
		return $this->gridViews;
	}

	public function jsonSerialize(): mixed {
		return [
			'name' => $this->name,
			'icon' => $this->icon,
			'description' => $this->description,
			'nodes' => $this->nodes,
			'pages' => $this->pages,
			'tables' => $this->tables,
			'menuItems' => $this->menuItems,
			'gridViews' => $this->gridViews,
		];
	}
}
