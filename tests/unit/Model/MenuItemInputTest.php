<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Model;

use InvalidArgumentException;
use OCA\Tables\Model\MenuItemInput;
use PHPUnit\Framework\TestCase;

class MenuItemInputTest extends TestCase {
	public function testCreatesEveryTargetTypeAndDropsWhatItDoesNotUse(): void {
		$items = MenuItemInput::createListFromInputArray([
			['label' => ' Intake home ', 'targetType' => 'view', 'targetId' => 6, 'technicalName' => 'home', 'url' => 'https://ignored.example'],
			['label' => 'Docs', 'targetType' => 'url', 'url' => 'https://example.org/docs', 'targetId' => 99, 'icon' => 'book'],
			['label' => 'Requests', 'targetType' => 'table', 'targetId' => 4, 'icon' => ''],
		]);

		$this->assertSame(['Intake home', null, 'view', 6, null, 'home'], $this->toArray($items[0]));
		$this->assertSame(['Docs', 'book', 'url', null, 'https://example.org/docs', null], $this->toArray($items[1]));
		$this->assertSame(['Requests', null, 'table', 4, null, null], $this->toArray($items[2]));
	}

	public function testATargetTypeOfUrlIsTheDefault(): void {
		$item = MenuItemInput::createFromInputArray(['label' => 'Docs', 'url' => 'http://example.org']);

		$this->assertSame('url', $item->getTargetType());
	}

	public static function invalidItems(): array {
		return [
			'not an object' => ['not an item', 'must be an object'],
			'no label' => [['targetType' => 'url', 'url' => 'https://example.org'], 'needs a label'],
			'blank label' => [['label' => '  ', 'targetType' => 'url', 'url' => 'https://example.org'], 'needs a label'],
			'label too long' => [['label' => str_repeat('x', 201), 'url' => 'https://example.org'], 'needs a label'],
			'unknown target type' => [['label' => 'X', 'targetType' => 'kanban', 'targetId' => 1], 'Unexpected menu item target type'],
			'view without id' => [['label' => 'X', 'targetType' => 'view'], 'needs its id'],
			'page without id' => [['label' => 'X', 'targetType' => 'page'], 'needs its id'],
			'id as a string' => [['label' => 'X', 'targetType' => 'table', 'targetId' => '4'], 'needs its id'],
			'id of zero' => [['label' => 'X', 'targetType' => 'table', 'targetId' => 0], 'needs its id'],
			'javascript link' => [['label' => 'X', 'url' => 'javascript:alert(1)'], 'http or https'],
			'data link' => [['label' => 'X', 'url' => 'data:text/html,hi'], 'http or https'],
			// carries a host, so only the scheme check stops it
			'javascript link with a host' => [['label' => 'X', 'url' => 'javascript://example.com/%0Aalert(1)'], 'http or https'],
			'link without host' => [['label' => 'X', 'url' => 'https://'], 'http or https'],
			'empty link' => [['label' => 'X', 'url' => ''], 'http or https'],
			'technical name with a hyphen' => [['label' => 'X', 'url' => 'https://example.org', 'technicalName' => 'my-page'], 'technical name'],
			'icon that is not a string' => [['label' => 'X', 'url' => 'https://example.org', 'icon' => 3], '"icon" must be a string'],
		];
	}

	/**
	 * @dataProvider invalidItems
	 */
	public function testRejectsInvalidInput(mixed $input, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		MenuItemInput::createFromInputArray($input);
	}

	public function testAPageItemWithoutAnIdOpensTheNewStartPageOnlyWhenAllowed(): void {
		[$startPage, $page] = MenuItemInput::createListFromInputArray([
			['label' => 'Dashboard', 'targetType' => 'page', 'technicalName' => 'home'],
			['label' => 'Overview', 'targetType' => 'page', 'targetId' => 5],
		], allowNewStartPage: true);

		$this->assertTrue($startPage->pointsAtNewStartPage());
		$this->assertSame(['Dashboard', null, 'page', null, null, 'home'], $this->toArray($startPage));
		$this->assertFalse($page->pointsAtNewStartPage());
		$this->assertSame(['Overview', null, 'page', 5, null, null], $this->toArray($page));

		$resolved = $startPage->withPageId(3);
		$this->assertFalse($resolved->pointsAtNewStartPage());
		$this->assertSame(['Dashboard', null, 'page', 3, null, 'home'], $this->toArray($resolved));
	}

	public function testOnlyAPageItemMayLeaveOutItsIdWhenTheStartPageIsAllowed(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('needs its id');
		MenuItemInput::createFromInputArray(['label' => 'X', 'targetType' => 'view'], allowNewStartPage: true);
	}

	public function testRejectsAMenuThatIsNotAList(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must be a list');
		MenuItemInput::createListFromInputArray(['first' => ['label' => 'X', 'url' => 'https://example.org']]);
	}

	private function toArray(MenuItemInput $item): array {
		return [$item->getLabel(), $item->getIcon(), $item->getTargetType(), $item->getTargetId(), $item->getUrl(), $item->getTechnicalName()];
	}
}
