<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Db;

use OCA\Tables\Db\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase {
	public function testJsonSerializeDecodesTheRule(): void {
		$format = Format::fromRow([
			'id' => '3',
			'technical_name' => 'uuid',
			'label' => 'UUID',
			'example' => null,
			'type' => 'text',
			'rule' => '{"match":[{"var":"value"},"^[0-9a-f-]{36}$"]}',
			'enabled' => '1',
			'version' => '1.0.0',
			'created_at' => '2026-10-20 09:00:00',
			'created_by' => null,
			'updated_at' => '2026-10-20 09:00:00',
			'updated_by' => null,
		]);

		$this->assertSame([
			'id' => 3,
			'technicalName' => 'uuid',
			'label' => 'UUID',
			'example' => null,
			'type' => 'text',
			'rule' => ['match' => [['var' => 'value'], '^[0-9a-f-]{36}$']],
			'enabled' => true,
			'version' => '1.0.0',
			'createdAt' => '2026-10-20 09:00:00',
			'createdBy' => null,
			'updatedAt' => '2026-10-20 09:00:00',
			'updatedBy' => null,
		], $format->jsonSerialize());
	}
}
