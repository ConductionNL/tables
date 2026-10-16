<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A widget on a grid page: its type, optional title and its place on the grid.
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
 */
class Widget extends Entity implements \JsonSerializable {
	protected ?int $pageId = null;
	protected ?string $type = null;
	protected ?string $title = null;
	protected ?int $gridX = null;
	protected ?int $gridY = null;
	protected ?int $gridWidth = null;
	protected ?int $gridHeight = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('page_id', 'integer');
		$this->addType('grid_x', 'integer');
		$this->addType('grid_y', 'integer');
		$this->addType('grid_width', 'integer');
		$this->addType('grid_height', 'integer');
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
		];
	}
}
