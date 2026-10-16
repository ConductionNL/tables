<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Migration;

use OCA\Tables\Migration\Version2400Date20261016000002;
use PHPUnit\Framework\TestCase;

class Version2400Date20261016000002Test extends TestCase {
	public function testBackfillNamesEveryPageUniquelyPerApplication(): void {
		$names = Version2400Date20261016000002::backfillNames([
			['id' => 1, 'context_id' => 10, 'page_type' => 'startpage', 'title' => null, 'technical_name' => null],
			['id' => 2, 'context_id' => 10, 'page_type' => 'grid', 'title' => 'Team overview', 'technical_name' => null],
			['id' => 3, 'context_id' => 10, 'page_type' => 'grid', 'title' => 'Team: overview', 'technical_name' => null],
			['id' => 4, 'context_id' => 10, 'page_type' => 'grid', 'title' => null, 'technical_name' => null],
			['id' => 5, 'context_id' => 10, 'page_type' => 'grid', 'title' => '!!', 'technical_name' => ''],
			['id' => 6, 'context_id' => 11, 'page_type' => 'startpage', 'title' => null, 'technical_name' => null],
			['id' => 7, 'context_id' => 11, 'page_type' => 'grid', 'title' => 'Team overview', 'technical_name' => null],
		]);

		$this->assertSame([
			1 => 'dashboard',
			2 => 'team_overview',
			3 => 'team_overview_2',
			4 => 'page_4',
			5 => 'page_5',
			6 => 'dashboard',
			7 => 'team_overview',
		], $names);
	}

	public function testBackfillKeepsFreeNamesAndRenamesDuplicates(): void {
		$names = Version2400Date20261016000002::backfillNames([
			['id' => 1, 'context_id' => 10, 'page_type' => 'startpage', 'title' => null, 'technical_name' => null],
			['id' => 2, 'context_id' => 10, 'page_type' => 'grid', 'title' => 'Board', 'technical_name' => 'dashboard'],
			['id' => 3, 'context_id' => 10, 'page_type' => 'grid', 'title' => 'Sales', 'technical_name' => 'sales'],
			['id' => 4, 'context_id' => 10, 'page_type' => 'grid', 'title' => 'Sales', 'technical_name' => 'sales'],
			['id' => 5, 'context_id' => 10, 'page_type' => 'grid', 'title' => 'Other', 'technical_name' => 'Bad Name'],
		]);

		$this->assertSame([
			1 => 'dashboard_2',
			4 => 'sales_2',
			5 => 'bad_name',
		], $names);
	}

	public function testBackfillLeavesNamedPagesAlone(): void {
		$this->assertSame([], Version2400Date20261016000002::backfillNames([
			['id' => 1, 'context_id' => 10, 'page_type' => 'startpage', 'title' => null, 'technical_name' => 'dashboard'],
			['id' => 2, 'context_id' => 10, 'page_type' => 'grid', 'title' => 'Sales', 'technical_name' => 'sales'],
		]));
		$this->assertSame([], Version2400Date20261016000002::backfillNames([]));
	}
}
