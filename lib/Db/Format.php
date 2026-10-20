<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Db;

use JsonSerializable;
use OCA\Tables\ResponseDefinitions;
use OCP\AppFramework\Db\Entity;

/**
 * A named format that a text or number column can use as its subtype. The rule is a
 * JSON Logic expression evaluated against {"value": <cell value>}.
 *
 * @psalm-import-type TablesFormat from ResponseDefinitions
 *
 * @method getTechnicalName(): string
 * @method setTechnicalName(string $technicalName): void
 * @method getLabel(): string
 * @method setLabel(string $label): void
 * @method getExample(): ?string
 * @method setExample(?string $example): void
 * @method getType(): string
 * @method setType(string $type): void
 * @method getRule(): string
 * @method setRule(string $rule): void
 * @method setEnabled(bool $enabled): void
 * @method isEnabled(): bool
 * @method getVersion(): string
 * @method setVersion(string $version): void
 * @method getCreatedAt(): string
 * @method setCreatedAt(string $createdAt): void
 * @method getCreatedBy(): ?string
 * @method setCreatedBy(?string $createdBy): void
 * @method getUpdatedAt(): string
 * @method setUpdatedAt(string $updatedAt): void
 * @method getUpdatedBy(): ?string
 * @method setUpdatedBy(?string $updatedBy): void
 */
class Format extends Entity implements JsonSerializable {
	protected ?string $technicalName = null;
	protected ?string $label = null;
	protected ?string $example = null;
	protected ?string $type = null;
	protected ?string $rule = null;
	protected ?bool $enabled = null;
	protected ?string $version = null;
	protected ?string $createdAt = null;
	protected ?string $createdBy = null;
	protected ?string $updatedAt = null;
	protected ?string $updatedBy = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('enabled', 'boolean');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getRuleAsArray(): array {
		return json_decode($this->getRule(), true, flags: JSON_THROW_ON_ERROR);
	}

	/**
	 * @psalm-return TablesFormat
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'technicalName' => $this->getTechnicalName(),
			'label' => $this->getLabel(),
			'example' => $this->getExample(),
			'type' => $this->getType(),
			'rule' => $this->getRuleAsArray(),
			'enabled' => $this->isEnabled(),
			'version' => $this->getVersion(),
			'createdAt' => $this->getCreatedAt(),
			'createdBy' => $this->getCreatedBy(),
			'updatedAt' => $this->getUpdatedAt(),
			'updatedBy' => $this->getUpdatedBy(),
		];
	}
}
