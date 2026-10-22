<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Db;

use OCA\Tables\Db\Widget;
use PHPUnit\Framework\TestCase;

class WidgetTest extends TestCase {
	public function testSettingsAreKeptAsOneJsonObject(): void {
		$widget = new Widget();
		$widget->setSettingsArray(['text' => 'Projects', 'showTitle' => false, 'level' => 2, 'ratio' => 1.5, 'color' => null]);

		$this->assertSame('{"text":"Projects","showTitle":false,"level":2,"ratio":1.5,"color":null}', $widget->getSettings());
		$this->assertSame(['text' => 'Projects', 'showTitle' => false, 'level' => 2, 'ratio' => 1.5, 'color' => null], $widget->getSettingsArray());
	}

	public function testNoSettingsAreStoredAsAnEmptyObject(): void {
		$widget = new Widget();
		$widget->setSettingsArray([]);

		$this->assertSame('{}', $widget->getSettings());
		$this->assertSame([], $widget->getSettingsArray());
	}

	public function testNoStoredValueReadsAsNoSettings(): void {
		$this->assertSame([], (new Widget())->getSettingsArray());
	}

	/**
	 * A stored value that is not a JSON object reads as no settings rather than failing.
	 */
	public function testAStoredValueThatIsNotAnObjectReadsAsNoSettings(): void {
		$widget = new Widget();
		foreach (['', '"text"', '42', 'null', '{broken'] as $stored) {
			$widget->setSettings($stored);
			$this->assertSame([], $widget->getSettingsArray(), $stored);
		}
	}
}
