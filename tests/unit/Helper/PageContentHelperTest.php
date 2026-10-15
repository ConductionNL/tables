<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Helper;

use OCA\Tables\Db\Context;
use OCA\Tables\Helper\PageContentHelper;
use PHPUnit\Framework\TestCase;

class PageContentHelperTest extends TestCase {
	public function testExistingRowsAreStackedFullWidthByOrder(): void {
		$content = PageContentHelper::readContent([
			7 => ['order' => 20, 'node_rel_id' => 2],
			5 => ['order' => 10, 'node_rel_id' => 1],
			9 => ['order' => 30, 'node_rel_id' => 3],
		]);

		$this->assertSame([7, 5, 9], array_keys($content));
		$this->assertSame(['x' => 0, 'y' => 0, 'w' => 12, 'h' => 5], $this->position($content[5]));
		$this->assertSame(['x' => 0, 'y' => 5, 'w' => 12, 'h' => 5], $this->position($content[7]));
		$this->assertSame(['x' => 0, 'y' => 10, 'w' => 12, 'h' => 5], $this->position($content[9]));
		$this->assertSame(5, $content[5]['id']);
		$this->assertSame(1, $content[5]['node_rel_id']);
		$this->assertSame('data', $content[5]['type']);
		$this->assertSame(['title' => '', 'showTitle' => false], $content[5]['configuration']);
		$this->assertSame([], $content[5]['content']);
	}

	public function testMixedRowsPlaceUnpositionedBelowTheLowestWidget(): void {
		$content = PageContentHelper::readContent([
			1 => ['order' => 10, 'node_rel_id' => null, 'type' => 'header', 'configuration' => '{"configuration":{"title":"Top"},"content":{"title":"Welcome"}}', 'grid_x' => 0, 'grid_y' => 0, 'grid_width' => 12, 'grid_height' => 2],
			2 => ['order' => 20, 'node_rel_id' => 4, 'type' => 'data', 'configuration' => null, 'grid_x' => 6, 'grid_y' => 2, 'grid_width' => 6, 'grid_height' => 8],
			3 => ['order' => 5, 'node_rel_id' => 5, 'type' => null, 'configuration' => null, 'grid_x' => null, 'grid_y' => null, 'grid_width' => null, 'grid_height' => null],
			4 => ['order' => 1, 'node_rel_id' => 6, 'type' => 'data', 'configuration' => null, 'grid_x' => 0, 'grid_y' => null, 'grid_width' => 6, 'grid_height' => 3],
		]);

		$this->assertSame(['x' => 0, 'y' => 0, 'w' => 12, 'h' => 2], $this->position($content[1]));
		$this->assertSame(['x' => 6, 'y' => 2, 'w' => 6, 'h' => 8], $this->position($content[2]));
		// a partly positioned row counts as unpositioned; both stack by order below y = 10
		$this->assertSame(['x' => 0, 'y' => 10, 'w' => 12, 'h' => 5], $this->position($content[4]));
		$this->assertSame(['x' => 0, 'y' => 15, 'w' => 12, 'h' => 5], $this->position($content[3]));
		$this->assertSame('header', $content[1]['type']);
		$this->assertNull($content[1]['node_rel_id']);
		$this->assertSame(['title' => 'Top', 'showTitle' => false], $content[1]['configuration']);
		$this->assertSame(['title' => 'Welcome', 'subtitle' => '', 'textAlign' => 'left', 'backgroundColor' => '', 'textColor' => ''], $content[1]['content']);
		$this->assertSame('data', $content[3]['type']);
	}

	public function testSerializedPageContentIsReadToo(): void {
		$content = PageContentHelper::readContent([
			3 => ['id' => 3, 'pageId' => 1, 'nodeRelId' => 8, 'order' => 100, 'type' => null, 'configuration' => null, 'gridX' => 1, 'gridY' => 2, 'gridWidth' => 3, 'gridHeight' => 4],
		]);

		$this->assertSame(8, $content[3]['node_rel_id']);
		$this->assertSame(8, $content[3]['nodeRelId']);
		$this->assertSame(1, $content[3]['pageId']);
		$this->assertSame(['x' => 1, 'y' => 2, 'w' => 3, 'h' => 4], $this->position($content[3]));
		$this->assertArrayNotHasKey('gridX', $content[3]);
	}

	public function testUnreadableConfigurationFallsBackToDefaults(): void {
		$this->assertSame(
			['configuration' => ['title' => '', 'showTitle' => true], 'content' => ['text' => '']],
			PageContentHelper::readConfiguration('text', '{not json'),
		);
		$this->assertSame(
			['configuration' => [], 'content' => ['custom' => 1]],
			PageContentHelper::readConfiguration('future', ['content' => ['custom' => 1]]),
		);
	}

	public function testOrderFollowsPositionTopToBottomThenLeftToRight(): void {
		$this->assertSame(
			['c' => 10, 'b' => 20, 'a' => 30, 'd' => 40],
			PageContentHelper::orderFromPositions([
				'a' => ['grid_x' => 0, 'grid_y' => 4],
				'b' => ['grid_x' => 6, 'grid_y' => 0],
				'c' => ['grid_x' => 0, 'grid_y' => 0],
				'd' => ['grid_x' => 6, 'grid_y' => 4],
			]),
		);
	}

	public function testContextOutputReadsEveryPage(): void {
		$context = new Context();
		$context->setSharing([]);
		$context->setNodes([]);
		$context->setPages([
			1 => ['id' => 1, 'page_type' => 'startpage', 'content' => [4 => ['order' => 10, 'node_rel_id' => 2]]],
			2 => ['id' => 2, 'page_type' => 'grid', 'content' => []],
		]);

		$pages = $context->jsonSerialize()['pages'];

		$this->assertSame(['x' => 0, 'y' => 0, 'w' => 12, 'h' => 5], $this->position($pages[1]['content'][4]));
		$this->assertSame('data', $pages[1]['content'][4]['type']);
		$this->assertSame([], $pages[2]['content']);
		// the entity keeps the stored values
		$this->assertArrayNotHasKey('type', $context->getPages()[1]['content'][4]);
	}

	/**
	 * @return array{x: mixed, y: mixed, w: mixed, h: mixed}
	 */
	private function position(array $entry): array {
		return ['x' => $entry['grid_x'], 'y' => $entry['grid_y'], 'w' => $entry['grid_width'], 'h' => $entry['grid_height']];
	}
}
