<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Settings;

use OCA\Tables\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * The formats columns can use and the format packs Tables ships. The page loads them through the
 * formats API, which only administrators can change.
 */
class AdminSettings implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'tables-settings-admin');
		return new TemplateResponse(Application::APP_ID, 'settings-admin');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
