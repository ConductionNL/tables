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

	public function testEveryTypeDescribesItsSizeAndSchemas(): void {
		$all = $this->types->all();

		$this->assertSame(['header', 'text', 'data'], array_keys($all));
		foreach ($all as $name => $type) {
			$this->assertSame($name, $type['type']);
			$this->assertSame(['title', 'showTitle'], array_keys($type['configuration']));
			$this->assertSame(GridWidgetTypes::defaults($name)['configuration'], array_map(static fn (array $property) => $property['default'], $type['configuration']));
			$this->assertSame(GridWidgetTypes::defaults($name)['content'], array_map(static fn (array $property) => $property['default'], $type['properties']));
		}
		$this->assertSame([12, 5], [$all['data']['defaultWidth'], $all['data']['defaultHeight']]);
		$this->assertSame([], $all['data']['properties']);
	}

	public function testTitlesAreTranslated(): void {
		$header = $this->types->get('header');

		$this->assertSame('[Header]', $header['title']);
		$this->assertSame('[Heading]', $header['properties']['title']['title']);
		$this->assertSame('[Centered]', $header['properties']['textAlign']['options'][1]['label']);
	}

	public function testUnknownTypeIsRejected(): void {
		$this->expectException(BadRequestError::class);
		$this->types->get('chart');
	}

	public function testUnknownTypeHasNoDefaults(): void {
		$this->assertSame(['configuration' => [], 'content' => []], GridWidgetTypes::defaults('chart'));
	}

	public function testSanitizeFillsDefaultsAndDropsUnknownProperties(): void {
		$this->assertSame([
			'configuration' => ['title' => 'Intro', 'showTitle' => true],
			'content' => ['title' => 'Welcome', 'subtitle' => '', 'textAlign' => 'center', 'backgroundColor' => '#fff', 'textColor' => ''],
		], $this->types->sanitize(
			'header',
			['title' => 'Intro', 'showTitle' => true, 'extra' => 1],
			['title' => 'Welcome', 'textAlign' => 'center', 'backgroundColor' => '#fff', 'script' => '<b>'],
		));
		$this->assertSame(
			['configuration' => ['title' => '', 'showTitle' => false], 'content' => []],
			$this->types->sanitize('data', null, ['target' => ['type' => 'table', 'id' => 1]]),
		);
	}

	public static function invalidWidgets(): array {
		return [
			'configuration not an object' => ['text', 'x', [], 'configuration of a text widget'],
			'content not an object' => ['text', [], 5, 'content of a text widget'],
			'required heading missing' => ['header', [], ['title' => '  '], 'header.title is required'],
			'text not a string' => ['text', [], ['text' => 3], 'text.text must be a string'],
			'show title not a boolean' => ['text', ['showTitle' => 'yes'], [], 'text.showTitle must be true or false'],
			'color not hex' => ['header', [], ['title' => 'A', 'textColor' => 'red'], 'header.textColor must be a hex color'],
			'alignment unknown' => ['header', [], ['title' => 'A', 'textAlign' => 'justify'], 'header.textAlign must be one of'],
		];
	}

	/**
	 * @dataProvider invalidWidgets
	 */
	public function testSanitizeRejectsInvalidInput(string $type, mixed $configuration, mixed $content, string $message): void {
		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage($message);
		$this->types->sanitize($type, $configuration, $content);
	}
}
