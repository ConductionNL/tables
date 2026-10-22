<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Db\WidgetMapper;
use OCA\Tables\Db\WidgetSourceMapper;
use OCA\Tables\Service\WidgetService;
use OCA\Tables\Tests\Unit\Database\DatabaseTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Runs against the database: widgets, their settings and sources.
 */
class WidgetServiceTest extends DatabaseTestCase {
	private const PAGE = 990001;
	private const OTHER_PAGE = 990002;

	private WidgetService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new WidgetService(
			new WidgetMapper($this->connectionAdapter),
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
		$this->assertNull($this->storedSettings($widget->getId()));
		$this->assertSame([], (new WidgetSourceMapper($this->connectionAdapter))->findNodeRelIdsByWidgetIds([$widget->getId()]));
		$this->assertSame(['nodeRelId' => 52], $this->service->findByPageIds([self::OTHER_PAGE])[self::OTHER_PAGE][0]['source']);
		$this->assertSame(['pageSize' => 20], $this->service->findByPageIds([self::OTHER_PAGE])[self::OTHER_PAGE][0]['settings']);
		$this->assertSame('{"pageSize":20}', $this->storedSettings($other->getId()));
	}

	public function testSettingsAreStoredAsOneJsonObjectOnTheWidgetRow(): void {
		$header = $this->service->insert(self::PAGE, 'header', null, $this->position(0, 0, 12, 2), ['text' => 'A', 'level' => 2, 'showTitle' => false, 'color' => null], null);
		$data = $this->service->insert(self::PAGE, 'data', null, $this->position(0, 2, 12, 5), [], 61);

		$this->assertSame('{"text":"A","level":2,"showTitle":false,"color":null}', $this->storedSettings($header->getId()));
		$this->assertSame('{}', $this->storedSettings($data->getId()));
		$this->assertSame([], $this->service->findByPageIds([self::PAGE])[self::PAGE][1]['settings']);
	}

	private function storedSettings(int $widgetId): ?string {
		$qb = $this->connectionAdapter->getQueryBuilder();
		$qb->select('settings')
			->from('tables_contexts_widget')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($widgetId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		return $value === false || $value === null ? null : (string)$value;
	}

	public function testSyncUpdatesListedInsertsNewAndDeletesMissingWidgets(): void {
		$kept = $this->service->insert(self::PAGE, 'header', null, $this->position(0, 0, 12, 2), ['heading' => 'Old', 'subheading' => 'Gone'], null);
		$removed = $this->service->insert(self::PAGE, 'data', null, $this->position(0, 2, 12, 5), [], 61);
		$other = $this->service->insert(self::OTHER_PAGE, 'text', null, $this->position(0, 0, 6, 2), ['text' => 'Stays'], null);

		$this->service->syncPage(self::PAGE, [
			['id' => $kept->getId(), 'type' => 'header', 'title' => 'Intro', 'position' => $this->position(0, 0, 6, 3), 'settings' => ['heading' => 'New'], 'nodeRelId' => null],
			['id' => null, 'type' => 'data', 'title' => null, 'position' => $this->position(6, 0, 6, 5), 'settings' => [], 'nodeRelId' => 62],
		]);

		$widgets = $this->service->findByPageIds([self::PAGE, self::OTHER_PAGE]);
		$this->assertCount(2, $widgets[self::PAGE]);
		[$header, $data] = $widgets[self::PAGE];
		$this->assertSame($kept->getId(), $header['id']);
		$this->assertSame(['Intro', 0, 0, 6, 3], [$header['title'], $header['gridX'], $header['gridY'], $header['gridWidth'], $header['gridHeight']]);
		$this->assertSame(['heading' => 'New'], $header['settings']);
		$this->assertNotSame($removed->getId(), $data['id']);
		$this->assertSame(['nodeRelId' => 62], $data['source']);
		$this->assertSame([], (new WidgetSourceMapper($this->connectionAdapter))->findNodeRelIdsByWidgetIds([$removed->getId()]));
		$this->assertSame($other->getId(), $widgets[self::OTHER_PAGE][0]['id']);
	}

	public function testSyncWithNoWidgetsEmptiesThePage(): void {
		$this->service->insert(self::PAGE, 'text', null, $this->position(0, 0, 6, 2), ['text' => 'A'], null);

		$this->service->syncPage(self::PAGE, []);

		$this->assertSame([], $this->service->findByPageIds([self::PAGE]));
	}

	/**
	 * @return array{gridX: int, gridY: int, gridWidth: int, gridHeight: int}
	 */
	private function position(int $x, int $y, int $width, int $height): array {
		return ['gridX' => $x, 'gridY' => $y, 'gridWidth' => $width, 'gridHeight' => $height];
	}
}
