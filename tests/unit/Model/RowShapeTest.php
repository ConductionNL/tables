<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Model;

use InvalidArgumentException;
use OCA\Tables\Model\RowShape;
use PHPUnit\Framework\TestCase;

class RowShapeTest extends TestCase {
	public function testMissingShapeIsCells(): void {
		$this->assertSame(RowShape::Cells, RowShape::fromRequest(null));
		$this->assertSame(RowShape::Cells, RowShape::fromRequest(''));
	}

	public function testKnownShapes(): void {
		$this->assertSame(RowShape::Cells, RowShape::fromRequest('cells'));
		$this->assertSame(RowShape::Object, RowShape::fromRequest('object'));
	}

	public function testUnknownShapeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		RowShape::fromRequest('objects');
	}
}
