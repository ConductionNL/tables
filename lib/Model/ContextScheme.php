<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Model;

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
		protected ?string $technicalName = null,
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
		foreach (['nodes', 'pages', 'menuItems', 'gridViews'] as $list) {
			if (isset($data[$list]) && !is_array($data[$list])) {
				throw new InvalidArgumentException('The scheme property "' . $list . '" must be a list.');
			}
		}
		if (!is_string($data['name'] ?? null) || trim($data['name']) === '') {
			throw new InvalidArgumentException('A scheme needs a name.');
		}
		foreach (['icon', 'description', 'technicalName'] as $optional) {
			if (isset($data[$optional]) && !is_string($data[$optional])) {
				throw new InvalidArgumentException('The scheme property "' . $optional . '" must be a string.');
			}
		}
		foreach ($data['pages'] ?? [] as $page) {
			if (!is_array($page) || (isset($page['content']) && !is_array($page['content']))) {
				throw new InvalidArgumentException('Every page of a scheme must be an object, with its content as a list.');
			}
		}
		self::assertGridViewsValid($data['gridViews'] ?? []);
		return new self(
			name: $data['name'],
			icon: $data['icon'] ?? null,
			description: $data['description'] ?? null,
			nodes: array_values($data['nodes'] ?? []),
			// A scheme without pages or without a menu leaves those of the application alone
			pages: isset($data['pages']) ? $data['pages'] : null,
			tables: $tables,
			menuItems: isset($data['menuItems']) ? array_values($data['menuItems']) : null,
			gridViews: array_values($data['gridViews'] ?? []),
			technicalName: isset($data['technicalName']) && $data['technicalName'] !== '' ? $data['technicalName'] : null,
		);
	}

	/**
	 * Grid views come from schemes of an older version, which kept pages in views without a table.
	 * Every one needs a uuid and a title, and its grid is an object.
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
			if (isset($gridView['grid']) && !is_array($gridView['grid'])) {
				throw new InvalidArgumentException('The grid view property "grid" must be an object.');
			}
		}
	}

	/**
	 * Null when the scheme gives none, which leaves the technical name of the application alone.
	 */
	public function getTechnicalName(): ?string {
		return $this->technicalName;
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

	/**
	 * Pages with their widgets; data widgets name their node by its id in the nodes of the scheme.
	 * Null when the scheme has no pages, which leaves the pages of the application as they are.
	 */
	public function getPages(): ?array {
		return $this->pages;
	}

	public function getTables(): ?array {
		return $this->tables;
	}

	/**
	 * Menu items point at tables and views by uuid and at pages by their id in the scheme, so a
	 * scheme can be imported on another instance. Null when the scheme has no menu, which leaves
	 * the application's menu as it is.
	 */
	public function getMenuItems(): ?array {
		return $this->menuItems;
	}

	/**
	 * Views without a table from a scheme of an older version; the import turns each into a page.
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
			'technicalName' => $this->technicalName,
		];
	}
}
