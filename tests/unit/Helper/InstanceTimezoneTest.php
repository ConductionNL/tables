<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Helper;

use OCA\Tables\Helper\InstanceTimezone;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class InstanceTimezoneTest extends TestCase {
	private function timezoneFor(string $configured): string {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())->method('getSystemValueString')->with('default_timezone', 'UTC')->willReturn($configured);
		$instanceTimezone = new InstanceTimezone($config);

		$name = $instanceTimezone->get()->getName();
		$this->assertSame($name, $instanceTimezone->get()->getName());
		return $name;
	}

	public function testTheConfiguredTimezoneIsUsed(): void {
		$this->assertSame('Europe/Amsterdam', $this->timezoneFor('Europe/Amsterdam'));
	}

	public function testAnUnknownTimezoneFallsBackToUtc(): void {
		$this->assertSame('UTC', $this->timezoneFor('Mars/Olympus_Mons'));
	}
}
