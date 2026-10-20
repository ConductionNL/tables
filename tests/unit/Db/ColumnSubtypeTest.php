<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Db;

use OCA\Tables\Db\Column;
use PHPUnit\Framework\TestCase;

/**
 * Runs the cases that tests/frontend/columnSubtypes.test.mjs runs as well.
 */
class ColumnSubtypeTest extends TestCase {
	private const SUBTYPE_VECTORS_FILE = __DIR__ . '/../../formats/subtype-vectors.json';

	/**
	 * @dataProvider subtypeVectors
	 */
	public function testResolvesTheBaseSubtypeAndFormat(string $type, string $subtype, string $baseSubtype, ?string $formatName): void {
		$column = $this->createColumn($type, $subtype);

		$this->assertSame($baseSubtype, $column->getBaseSubtype());
		$this->assertSame($formatName, $column->getFormatName());
	}

	public function testResponseCarriesTheBaseSubtypeAndWhetherItIsBroken(): void {
		$column = $this->createColumn(Column::TYPE_TEXT, 'email');
		$column->setBroken(true);

		$serialized = $column->jsonSerialize();

		$this->assertSame('email', $serialized['subtype']);
		$this->assertSame(Column::SUBTYPE_TEXT_LINE, $serialized['baseSubtype']);
		$this->assertTrue($serialized['broken']);
	}

	public function testAColumnIsNotBrokenUntilMarkedSo(): void {
		$this->assertFalse($this->createColumn(Column::TYPE_TEXT, 'email')->jsonSerialize()['broken']);
	}

	public function testBrokenIsNotStored(): void {
		$column = $this->createColumn(Column::TYPE_TEXT, 'email');
		$column->resetUpdatedFields();

		$column->setBroken(true);

		$this->assertTrue($column->isBroken());
		$this->assertArrayNotHasKey('broken', $column->getUpdatedFields());
	}

	/**
	 * @return iterable<string, array{string, string, string, ?string}>
	 */
	public static function subtypeVectors(): iterable {
		$cases = json_decode((string)file_get_contents(self::SUBTYPE_VECTORS_FILE), true, flags: JSON_THROW_ON_ERROR);
		foreach ($cases as $case) {
			yield $case['type'] . ' "' . $case['subtype'] . '"' => [$case['type'], $case['subtype'], $case['baseSubtype'], $case['formatName']];
		}
	}

	private function createColumn(string $type, string $subtype): Column {
		$column = new Column();
		$column->setType($type);
		$column->setSubtype($subtype);
		return $column;
	}
}
