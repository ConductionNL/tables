<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One setting of a widget. The value is JSON encoded.
 *
 * @method getWidgetId(): int
 * @method setWidgetId(int $value): void
 * @method getName(): string
 * @method setName(string $value): void
 * @method getValue(): ?string
 * @method setValue(?string $value): void
 */
class WidgetSetting extends Entity {
	protected ?int $widgetId = null;
	protected ?string $name = null;
	protected ?string $value = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('widget_id', 'integer');
	}
}
