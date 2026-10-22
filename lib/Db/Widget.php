<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Db;

use OCA\Tables\ResponseDefinitions;
use OCP\AppFramework\Db\Entity;

/**
 * A widget on a grid page: its type, optional title, its place on the grid and its
 * settings, stored as one JSON object.
 *
 * @psalm-import-type TablesWidgetValue from ResponseDefinitions
 *
 * @method getPageId(): int
 * @method setPageId(int $value): void
 * @method getType(): string
 * @method setType(string $value): void
 * @method getTitle(): ?string
 * @method setTitle(?string $value): void
 * @method getGridX(): int
 * @method setGridX(int $value): void
 * @method getGridY(): int
 * @method setGridY(int $value): void
 * @method getGridWidth(): int
 * @method setGridWidth(int $value): void
 * @method getGridHeight(): int
 * @method setGridHeight(int $value): void
 * @method getSettings(): ?string
 * @method setSettings(?string $value): void
 */
class Widget extends Entity implements \JsonSerializable {
	protected ?int $pageId = null;
	protected ?string $type = null;
	protected ?string $title = null;
	protected ?int $gridX = null;
	protected ?int $gridY = null;
	protected ?int $gridWidth = null;
	protected ?int $gridHeight = null;
	protected ?string $settings = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('page_id', 'integer');
		$this->addType('grid_x', 'integer');
		$this->addType('grid_y', 'integer');
		$this->addType('grid_width', 'integer');
		$this->addType('grid_height', 'integer');
	}

	/**
	 * @return array<string, TablesWidgetValue>
	 */
	public function getSettingsArray(): array {
		$settings = $this->getSettings();
		if ($settings === null || $settings === '') {
			return [];
		}
		$decoded = json_decode($settings, true);
		/** @var array<string, TablesWidgetValue> */
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Stores the settings as one JSON object, or no value when there are none.
	 *
	 * @param array<string, TablesWidgetValue> $settings
	 * @throws \JsonException
	 */
	public function setSettingsArray(array $settings): void {
		$this->setSettings($settings === [] ? null : json_encode($settings, JSON_THROW_ON_ERROR));
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'pageId' => $this->getPageId(),
			'type' => $this->getType(),
			'title' => $this->getTitle(),
			'gridX' => $this->getGridX(),
			'gridY' => $this->getGridY(),
			'gridWidth' => $this->getGridWidth(),
			'gridHeight' => $this->getGridHeight(),
			'settings' => $this->getSettingsArray(),
		];
	}
}
