<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method getContextId(): int
 * @method setContextId(int $value): void
 * @method getPageType(): string
 * @method setPageType(string $value): void
 * @method getTitle(): ?string
 * @method setTitle(?string $value): void
 * @method getEmoji(): ?string
 * @method setEmoji(?string $value): void
 * @method getDescription(): ?string
 * @method setDescription(?string $value): void
 */
class Page extends Entity implements \JsonSerializable {
	public const TYPE_STARTPAGE = 'startpage';
	public const TYPE_GRID = 'grid';

	protected ?int $contextId = null;
	protected ?string $pageType = null;
	protected ?string $title = null;
	protected ?string $emoji = null;
	protected ?string $description = null;

	public function __construct() {
		$this->addType('id', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'contextId' => $this->getContextId(),
			'pageType' => $this->getPageType(),
			'title' => $this->getTitle(),
			'emoji' => $this->getEmoji(),
			'description' => $this->getDescription(),
		];
	}
}
