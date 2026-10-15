<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Helper;

use OCA\Tables\Helper\TechnicalNameHelper;
use PHPUnit\Framework\TestCase;

class TechnicalNameHelperTest extends TestCase {
	public static function textProvider(): array {
		return [
			'plain' => ['Sales', 'sales'],
			'spaces and punctuation' => ['Open  cases (2026)!', 'open_cases_2026'],
			'trimmed' => ['  --Team overview--  ', 'team_overview'],
			'non-ascii' => ['Café über', 'caf_ber'],
			'leading digit' => ['2026 plan', 'page_2026_plan'],
			'only punctuation' => ['!!!', 'page'],
			'empty' => ['', 'page'],
			'already valid' => ['open_cases_2', 'open_cases_2'],
		];
	}

	/**
	 * @dataProvider textProvider
	 */
	public function testFromText(string $text, string $expected): void {
		$this->assertSame($expected, TechnicalNameHelper::fromText($text));
	}

	public function testFromTextUsesThePrefix(): void {
		$this->assertSame('item', TechnicalNameHelper::fromText('', 'item'));
		$this->assertSame('item_1st', TechnicalNameHelper::fromText('1st', 'item'));
	}

	public function testFromTextIsCutToTheMaximumLength(): void {
		$name = TechnicalNameHelper::fromText(str_repeat('a', 199) . ' b c');

		$this->assertSame(str_repeat('a', 199), $name);
		$this->assertTrue(TechnicalNameHelper::isValid($name));
	}

	public function testUniqueAddsTheLowestFreeSuffix(): void {
		$this->assertSame('sales', TechnicalNameHelper::unique('sales', []));
		$this->assertSame('sales_2', TechnicalNameHelper::unique('sales', ['sales' => true]));
		$this->assertSame('sales_3', TechnicalNameHelper::unique('sales', ['sales' => true, 'sales_2' => true]));
	}

	public function testUniqueStaysWithinTheMaximumLength(): void {
		$base = str_repeat('a', 200);

		$name = TechnicalNameHelper::unique($base, [$base => true]);

		$this->assertSame(str_repeat('a', 198) . '_2', $name);
	}

	public function testAssignKeepsFreeNamesAndMakesTheRestUnique(): void {
		$names = TechnicalNameHelper::assign([
			'a' => ['name' => null, 'fallback' => 'dashboard'],
			'b' => ['name' => 'dashboard', 'fallback' => 'overview'],
			'c' => ['name' => 'sales', 'fallback' => 'sales'],
			'd' => ['name' => 'sales', 'fallback' => 'sales'],
			'e' => ['name' => 'Not Valid', 'fallback' => 'x'],
			'f' => ['name' => '', 'fallback' => 'archive'],
		], ['archive' => true]);

		$this->assertSame([
			'a' => 'dashboard_2',
			'b' => 'dashboard',
			'c' => 'sales',
			'd' => 'sales_2',
			'e' => 'not_valid',
			'f' => 'archive_2',
		], $names);
	}

	public function testPageFallback(): void {
		$this->assertSame('dashboard', TechnicalNameHelper::pageFallback(true, 'Anything'));
		$this->assertSame('team_overview', TechnicalNameHelper::pageFallback(false, 'Team overview'));
		$this->assertSame('page', TechnicalNameHelper::pageFallback(false, null));
	}
}
