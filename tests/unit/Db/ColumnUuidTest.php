<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Db;

use OCA\Tables\Db\Column;
use OCA\Tables\Vendor\Symfony\Component\Uid\Uuid;
use PHPUnit\Framework\TestCase;

class ColumnUuidTest extends TestCase {
	public function testSettingNoUuidAssignsANewOne(): void {
		$column = new Column();
		$column->setUuid(null);

		$this->assertTrue(Uuid::isValid((string)$column->getUuid()));
	}

	public function testKeepsAGivenUuid(): void {
		$column = new Column();
		$column->setUuid('0192a6e4-0000-7000-8000-000000000001');

		$this->assertSame('0192a6e4-0000-7000-8000-000000000001', $column->getUuid());
	}
}
