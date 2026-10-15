<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method getPageId(): int
 * @method setPageId(int $value): void
 * @method getNodeRelId(): ?int
 * @method setNodeRelId(?int $value): void
 * @method getOrder(): int
 * @method setOrder(int $value): void
 * @method getType(): string
 * @method setType(string $value): void
 * @method getConfiguration(): ?string
 * @method setConfiguration(?string $value): void
 * @method getGridX(): ?int
 * @method setGridX(?int $value): void
 * @method getGridY(): ?int
 * @method setGridY(?int $value): void
 * @method getGridWidth(): ?int
 * @method setGridWidth(?int $value): void
 * @method getGridHeight(): ?int
 * @method setGridHeight(?int $value): void
 */
class PageContent extends Entity implements \JsonSerializable {
	protected ?int $pageId = null;
	protected ?int $nodeRelId = null;
	protected ?int $order = null;
	protected ?string $type = null;
	protected ?string $configuration = null;
	protected ?int $gridX = null;
	protected ?int $gridY = null;
	protected ?int $gridWidth = null;
	protected ?int $gridHeight = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('page_id', 'integer');
		$this->addType('node_rel_id', 'integer');
		$this->addType('order', 'integer');
		$this->addType('grid_x', 'integer');
		$this->addType('grid_y', 'integer');
		$this->addType('grid_width', 'integer');
		$this->addType('grid_height', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'pageId' => $this->getPageId(),
			'nodeRelId' => $this->getNodeRelId(),
			'order' => $this->getOrder(),
			'type' => $this->getType(),
			'configuration' => $this->getConfiguration(),
			'gridX' => $this->getGridX(),
			'gridY' => $this->getGridY(),
			'gridWidth' => $this->getGridWidth(),
			'gridHeight' => $this->getGridHeight(),
		];
	}
}
