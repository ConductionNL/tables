<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Model;

use InvalidArgumentException;
use OCA\Tables\Model\ContextScheme;
use PHPUnit\Framework\TestCase;

final class ContextSchemeTest extends TestCase {
	private const TABLES = ['addTables' => [], 'modifyTables' => []];

	public function testCreateFromInputCarriesEveryPart(): void {
		$scheme = ContextScheme::createFromInputArray([
			'name' => 'Intake', 'icon' => 'inbox', 'description' => 'desc',
			'nodes' => [['node_type' => 0, 'node_uuid' => 'u1', 'permissions' => 1]],
			'tables' => self::TABLES,
			'menuItems' => [['label' => 'Home', 'targetType' => 'view', 'targetUuid' => 'v1']],
			'gridViews' => [['uuid' => 'v1', 'title' => 'Home']],
		]);

		$this->assertSame('Intake', $scheme->getName());
		$this->assertSame('inbox', $scheme->getIcon());
		$this->assertCount(1, $scheme->getNodes());
		$this->assertCount(1, $scheme->getMenuItems());
		$this->assertCount(1, $scheme->getGridViews());
		$this->assertSame(self::TABLES, $scheme->getTables());
	}

	public function testCreateFromInputKeepsAnAbsentMenuApartFromAnEmptyOne(): void {
		$withoutMenu = ContextScheme::createFromInputArray(['name' => 'Intake', 'tables' => self::TABLES]);
		$emptyMenu = ContextScheme::createFromInputArray(['name' => 'Intake', 'tables' => self::TABLES, 'menuItems' => []]);

		$this->assertNull($withoutMenu->getMenuItems());
		$this->assertSame([], $emptyMenu->getMenuItems());
		$this->assertSame([], $withoutMenu->getGridViews());
	}

	/**
	 * @dataProvider provideInvalidGridViews
	 */
	public function testCreateFromInputRejectsAnInvalidGridView(array $gridView, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		ContextScheme::createFromInputArray(['name' => 'Intake', 'tables' => self::TABLES, 'gridViews' => [$gridView]]);
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function provideInvalidGridViews(): array {
		return [
			'no uuid' => [['title' => 'Home'], '"uuid"'],
			'a blank title' => [['uuid' => 'v1', 'title' => ' '], '"title"'],
			'an emoji that is not text' => [['uuid' => 'v1', 'title' => 'Home', 'emoji' => 5], '"emoji" must be a string'],
			'a date in another format' => [['uuid' => 'v1', 'title' => 'Home', 'createdAt' => '14-10-2026'], '"createdAt" must be a date'],
			'a grid that is not an object' => [['uuid' => 'v1', 'title' => 'Home', 'grid' => 'widgets'], '"grid" must be an object'],
		];
	}

	public function testCreateFromInputAcceptsACompleteGridView(): void {
		$scheme = ContextScheme::createFromInputArray(['name' => 'Intake', 'tables' => self::TABLES, 'gridViews' => [[
			'uuid' => 'v1', 'title' => 'Home', 'emoji' => '🏠', 'description' => null,
			'createdAt' => '2026-10-14 12:00:00', 'lastEditAt' => '2026-10-14 12:00:00', 'grid' => ['widgets' => [], 'layout' => []],
		]]]);

		$this->assertCount(1, $scheme->getGridViews());
	}

	public function testCreateFromInputRejectsTablesWithoutBothLists(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('addTables');
		ContextScheme::createFromInputArray(['name' => 'Intake', 'tables' => ['addTables' => []]]);
	}

	public function testCreateFromInputRejectsAMissingName(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('name');
		ContextScheme::createFromInputArray(['name' => ' ', 'tables' => self::TABLES]);
	}

	public function testCreateFromInputRejectsANonListMenu(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('menuItems');
		ContextScheme::createFromInputArray(['name' => 'Intake', 'tables' => self::TABLES, 'menuItems' => 'home']);
	}

	public function testCreateFromInputRejectsAnIconThatIsNotAString(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('"icon" must be a string');
		ContextScheme::createFromInputArray(['name' => 'Intake', 'tables' => self::TABLES, 'icon' => 7]);
	}
}
