<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Migration;

use OCA\Tables\AppInfo\Application;
use PHPUnit\Framework\TestCase;

final class Version2500Date20261026000000Test extends TestCase {
	public function testGivesTablesAndViewsASlugByTheirNodeId(): void {
		$slugs = Version2500Date20261026000000::backfillNodeSlugs([
			$this->row(1, 10, 5, Application::NODE_TYPE_TABLE),
			$this->row(2, 10, 7, Application::NODE_TYPE_VIEW),
		]);

		$this->assertSame([1 => 'table-5', 2 => 'view-7'], $slugs);
	}

	public function testKeepsExistingSlugsAndAvoidsThem(): void {
		$slugs = Version2500Date20261026000000::backfillNodeSlugs([
			$this->row(1, 10, 5, Application::NODE_TYPE_TABLE),
			$this->row(2, 10, 6, Application::NODE_TYPE_TABLE, 'table-5'),
		]);

		$this->assertSame([1 => 'table-5-2'], $slugs);
	}

	public function testAddsASuffixWhenTheSameNodeIsInAnApplicationTwice(): void {
		$slugs = Version2500Date20261026000000::backfillNodeSlugs([
			$this->row(1, 10, 5, Application::NODE_TYPE_TABLE),
			$this->row(2, 10, 5, Application::NODE_TYPE_TABLE),
			$this->row(3, 11, 5, Application::NODE_TYPE_TABLE),
		]);

		$this->assertSame([1 => 'table-5', 2 => 'table-5-2', 3 => 'table-5'], $slugs);
	}

	public function testTreatsAnEmptySlugAsMissing(): void {
		$slugs = Version2500Date20261026000000::backfillNodeSlugs([
			$this->row(1, 10, 5, Application::NODE_TYPE_TABLE, ''),
		]);

		$this->assertSame([1 => 'table-5'], $slugs);
	}

	/**
	 * @return array{id: int, context_id: int, node_id: int, node_type: int, slug: ?string}
	 */
	private function row(int $id, int $contextId, int $nodeId, int $nodeType, ?string $slug = null): array {
		return [
			'id' => $id,
			'context_id' => $contextId,
			'node_id' => $nodeId,
			'node_type' => $nodeType,
			'slug' => $slug,
		];
	}
}
