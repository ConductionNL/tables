<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Db\WidgetMapper;
use OCA\Tables\Db\WidgetSetting;
use OCA\Tables\Db\WidgetSettingMapper;
use OCA\Tables\Db\WidgetSourceMapper;
use OCA\Tables\Service\WidgetService;
use OCA\Tables\Tests\Unit\Database\DatabaseTestCase;
use OCP\DB\Exception;

/**
 * Runs against the database: widgets, their settings and sources.
 */
class WidgetServiceTest extends DatabaseTestCase {
	private const PAGE = 990001;
	private const OTHER_PAGE = 990002;

	private WidgetService $service;
	private WidgetSettingMapper $settingMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->settingMapper = new WidgetSettingMapper($this->connectionAdapter);
		$this->service = new WidgetService(
			new WidgetMapper($this->connectionAdapter),
			$this->settingMapper,
			new WidgetSourceMapper($this->connectionAdapter),
		);
	}

	protected function tearDown(): void {
		$this->service->deleteByPageIds([self::PAGE, self::OTHER_PAGE]);
		parent::tearDown();
	}

	public function testInsertedWidgetsAreReadBackWithSettingsAndSource(): void {
		$data = $this->service->insert(self::PAGE, 'data', 'Tasks', $this->position(0, 4, 12, 5), [], 31);
		$header = $this->service->insert(self::PAGE, 'header', null, $this->position(0, 0, 12, 2), [
			'text' => 'Projects',
			'showTitle' => false,
			'level' => 2,
			'color' => null,
		], null);
		$this->service->insert(self::OTHER_PAGE, 'text', null, $this->position(0, 0, 6, 2), ['text' => 'Other'], null);

		$byPage = $this->service->findByPageIds([self::PAGE]);

		$this->assertSame([self::PAGE], array_keys($byPage));
		$this->assertSame([
			[
				'id' => $header->getId(),
				'type' => 'header',
				'title' => null,
				'gridX' => 0,
				'gridY' => 0,
				'gridWidth' => 12,
				'gridHeight' => 2,
				'settings' => ['text' => 'Projects', 'showTitle' => false, 'level' => 2, 'color' => null],
				'source' => null,
			],
			[
				'id' => $data->getId(),
				'type' => 'data',
				'title' => 'Tasks',
				'gridX' => 0,
				'gridY' => 4,
				'gridWidth' => 12,
				'gridHeight' => 5,
				'settings' => [],
				'source' => ['nodeRelId' => 31],
			],
		], $byPage[self::PAGE]);
	}

	public function testRemovingANodeKeepsTheWidgetWithoutSource(): void {
		$kept = $this->service->insert(self::PAGE, 'data', null, $this->position(0, 0, 6, 5), [], 41);
		$unlinked = $this->service->insert(self::PAGE, 'data', null, $this->position(6, 0, 6, 5), ['pageSize' => 10], 42);

		$this->service->deleteSourcesByNodeRelIds([42]);

		$widgets = $this->service->findByPageIds([self::PAGE])[self::PAGE];
		$this->assertSame([$kept->getId(), $unlinked->getId()], array_column($widgets, 'id'));
		$this->assertSame(['nodeRelId' => 41], $widgets[0]['source']);
		$this->assertNull($widgets[1]['source']);
		$this->assertSame(['pageSize' => 10], $widgets[1]['settings']);
	}

	public function testDeletingPagesDeletesWidgetsSettingsAndSources(): void {
		$widget = $this->service->insert(self::PAGE, 'data', null, $this->position(0, 0, 6, 5), ['pageSize' => 10], 51);
		$other = $this->service->insert(self::OTHER_PAGE, 'data', null, $this->position(0, 0, 6, 5), ['pageSize' => 20], 52);

		$this->service->deleteByPageIds([self::PAGE]);

		$this->assertSame([], $this->service->findByPageIds([self::PAGE]));
		$this->assertSame([], $this->settingMapper->findSettingsByWidgetIds([$widget->getId()]));
		$this->assertSame([], (new WidgetSourceMapper($this->connectionAdapter))->findNodeRelIdsByWidgetIds([$widget->getId()]));
		$this->assertSame(['nodeRelId' => 52], $this->service->findByPageIds([self::OTHER_PAGE])[self::OTHER_PAGE][0]['source']);
		$this->assertSame([$other->getId() => ['pageSize' => 20]], $this->settingMapper->findSettingsByWidgetIds([$other->getId()]));
	}

	public function testReplacingSettingsDropsSettingsThatAreNotGiven(): void {
		$widget = $this->service->insert(self::PAGE, 'header', null, $this->position(0, 0, 12, 2), ['text' => 'A', 'subtitle' => 'B'], null);

		$this->settingMapper->replaceSettings($widget->getId(), ['text' => 'C']);

		$this->assertSame([$widget->getId() => ['text' => 'C']], $this->settingMapper->findSettingsByWidgetIds([$widget->getId()]));
	}

	public function testASettingNameIsUniquePerWidget(): void {
		$widget = $this->service->insert(self::PAGE, 'header', null, $this->position(0, 0, 12, 2), ['text' => 'A'], null);
		$duplicate = new WidgetSetting();
		$duplicate->setWidgetId($widget->getId());
		$duplicate->setName('text');
		$duplicate->setValue('"B"');

		try {
			$this->settingMapper->insert($duplicate);
			$this->fail('A second setting with the same name was stored.');
		} catch (Exception $e) {
			$this->assertSame(Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, $e->getReason());
		}
	}

	/**
	 * @return array{gridX: int, gridY: int, gridWidth: int, gridHeight: int}
	 */
	private function position(int $x, int $y, int $width, int $height): array {
		return ['gridX' => $x, 'gridY' => $y, 'gridWidth' => $width, 'gridHeight' => $height];
	}
}
