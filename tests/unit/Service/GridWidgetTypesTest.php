<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Service\GridWidgetTypes;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class GridWidgetTypesTest extends TestCase {
	private GridWidgetTypes $types;

	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '[' . $text . ']');
		$this->types = new GridWidgetTypes($l10n);
	}

	public function testEveryTypeDescribesItsSizeSourceAndSettings(): void {
		$all = $this->types->all();

		$this->assertSame(['header', 'text', 'data'], array_keys($all));
		foreach ($all as $name => $type) {
			$this->assertSame($name, $type['type']);
			$this->assertSame($name === 'data', $type['hasSource']);
		}
		$this->assertSame(['heading', 'subheading', 'textAlign', 'backgroundColor', 'textColor'], array_keys($all['header']['settings']));
		$this->assertSame([12, 5], [$all['data']['defaultWidth'], $all['data']['defaultHeight']]);
		$this->assertSame([], $all['data']['settings']);
	}

	public function testTitlesAreTranslated(): void {
		$header = $this->types->get('header');

		$this->assertSame('[Header]', $header['title']);
		$this->assertSame('[Heading]', $header['settings']['heading']['title']);
		$this->assertSame('[Centered]', $header['settings']['textAlign']['options'][1]['label']);
		$this->assertSame('[Description]', $this->types->get('text')['title']);
	}

	public function testUnknownTypeIsRejected(): void {
		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage('Unknown widget type "chart"');
		$this->types->get('chart');
	}

	public function testSanitizeFillsDefaultsAndDropsUnknownSettings(): void {
		$this->assertSame(
			['heading' => 'Welcome', 'subheading' => '', 'textAlign' => 'center', 'backgroundColor' => '#fff', 'textColor' => ''],
			$this->types->sanitizeSettings('header', ['heading' => 'Welcome', 'textAlign' => 'center', 'backgroundColor' => '#fff', 'script' => '<b>']),
		);
		$this->assertSame(['text' => ''], $this->types->sanitizeSettings('text', null));
		$this->assertSame([], $this->types->sanitizeSettings('data', ['pageSize' => 10]));
	}

	public static function invalidSettings(): array {
		return [
			'settings not an object' => ['text', 'x', 'settings of a text widget must be an object'],
			'required heading missing' => ['header', ['heading' => '  '], 'header.heading is required'],
			'text not a string' => ['text', ['text' => 3], 'text.text must be a string'],
			'color not hex' => ['header', ['heading' => 'A', 'textColor' => 'red'], 'header.textColor must be a hex color'],
			'alignment unknown' => ['header', ['heading' => 'A', 'textAlign' => 'justify'], 'header.textAlign must be one of'],
		];
	}

	/**
	 * @dataProvider invalidSettings
	 */
	public function testSanitizeRejectsInvalidSettings(string $type, mixed $settings, string $message): void {
		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage($message);
		$this->types->sanitizeSettings($type, $settings);
	}
}
