<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Db;

use OCA\Tables\Helper\TechnicalNameHelper;
use OCP\AppFramework\Db\Entity;

/**
 * @method getContextId(): int
 * @method setContextId(int $value): void
 * @method getPageType(): string
 * @method setPageType(string $value): void
 * @method getTitle(): ?string
 * @method setTitle(?string $value): void
 * @method getTechnicalName(): ?string
 * @method setTechnicalName(?string $value): void
 */
class Page extends Entity implements \JsonSerializable {
	public const TYPE_STARTPAGE = 'startpage';
	public const TYPE_GRID = 'grid';
	public const TECHNICAL_NAME_PATTERN = TechnicalNameHelper::PATTERN;
	public const TECHNICAL_NAME_MAX_LENGTH = TechnicalNameHelper::MAX_LENGTH;

	protected ?int $contextId = null;
	protected ?string $pageType = null;
	protected ?string $title = null;
	protected ?string $technicalName = null;

	public function __construct() {
		$this->addType('id', 'integer');
	}

	public static function isValidTechnicalName(string $technicalName): bool {
		return TechnicalNameHelper::isValid($technicalName);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'contextId' => $this->getContextId(),
			'pageType' => $this->getPageType(),
			'title' => $this->getTitle(),
			'technicalName' => $this->getTechnicalName(),
		];
	}
}
