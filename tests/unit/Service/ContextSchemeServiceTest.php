<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use OCA\Tables\AppInfo\Application;
use OCA\Tables\Db\Context;
use OCA\Tables\Db\Table;
use OCA\Tables\Db\TableMapper;
use OCA\Tables\Db\View;
use OCA\Tables\Db\ViewMapper;
use OCA\Tables\Model\ContextScheme;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ContextSchemeServiceTest extends TestCase {
	private const USER = 'importer';

	private ContextSchemeService $service;
	private PageService&MockObject $pageService;
	private TableMapper&MockObject $tableMapper;
	private ViewMapper&MockObject $viewMapper;
	private PermissionsService&MockObject $permissionsService;

	protected function setUp(): void {
		parent::setUp();
		$this->pageService = $this->createMock(PageService::class);
		$this->tableMapper = $this->createMock(TableMapper::class);
		$this->viewMapper = $this->createMock(ViewMapper::class);
		$this->permissionsService = $this->createMock(PermissionsService::class);
		$this->service = new ContextSchemeService(
			$this->pageService,
			$this->tableMapper,
			$this->viewMapper,
			$this->permissionsService,
			$this->createMock(LoggerInterface::class),
		);

		// Tables and views exist here under the uuids "table-<id>" and "view-<id>"
		$this->tableMapper->method('findByUuid')->willReturnCallback(static function (string $uuid): Table {
			if (!str_starts_with($uuid, 'table-')) {
				throw new DoesNotExistException('no table ' . $uuid);
			}
			$table = new Table();
			$table->setId((int)substr($uuid, 6));
			return $table;
		});
		$this->viewMapper->method('findByUuid')->willReturnCallback(static function (string $uuid): ?View {
			if (!str_starts_with($uuid, 'view-')) {
				return null;
			}
			$view = new View();
			$view->setId((int)substr($uuid, 5));
			return $view;
		});
		$this->tableMapper->method('find')->willReturnCallback(static function (int $id): Table {
			if ($id === 404) {
				throw new DoesNotExistException('gone');
			}
			$table = new Table();
			$table->setId($id);
			$table->setUuid('table-' . $id);
			return $table;
		});
		$this->viewMapper->method('find')->willReturnCallback(static function (int $id): View {
			$view = new View();
			$view->setId($id);
			$view->setUuid('view-' . $id);
			return $view;
		});
	}

	private function context(array $nodes = [], array $menuItems = []): Context {
		$context = new Context();
		$context->id = 7;
		$context->setNodes($nodes);
		$context->setPages([]);
		$context->setMenuItems($menuItems);
		return $context;
	}

	private function menuItem(int $id, string $technicalName, string $targetType, ?int $targetId, ?int $parentId = null, bool $broken = false): array {
		return [
			'id' => $id, 'contextId' => 7, 'label' => ucfirst($technicalName), 'icon' => null, 'targetType' => $targetType, 'targetId' => $targetId,
			'url' => $targetType === 'url' ? 'https://example.org' : null, 'technicalName' => $technicalName, 'order' => $id * 10,
			'parentId' => $parentId, 'broken' => $broken,
		];
	}

	/**
	 * @param list<array<string, mixed>> $pages the pages the application has here
	 * @param array<int, list<array<string, mixed>>> $saved collects the widgets saved per page
	 * @param list<array{0: string, 1: string|null}> $created collects the pages created
	 */
	private function expectPages(array $pages, array &$saved, array &$created = []): void {
		$this->pageService->method('findAll')->willReturn($pages);
		$this->pageService->method('saveWidgets')->willReturnCallback(static function (int $contextId, int $pageId, array $widgets) use (&$saved): array {
			$saved[$pageId] = $widgets;
			return [];
		});
		$this->pageService->method('create')->willReturnCallback(static function (int $contextId, string $title, ?string $technicalName) use (&$created): array {
			$created[] = [$title, $technicalName];
			return ['id' => 100 + count($created)];
		});
	}

	private function startPage(int $id = 1): array {
		return ['id' => $id, 'page_type' => 'startpage', 'title' => null, 'technical_name' => null, 'content' => []];
	}

	public function testExportNamesTablesAndViewsByUuidAndPagesByIdAndSkipsBrokenItems(): void {
		$context = $this->context(menuItems: [
			$this->menuItem(1, 'dashboard', 'page', 3),
			$this->menuItem(2, 'cases', 'table', 5, 1),
			$this->menuItem(3, 'open', 'view', 6),
			$this->menuItem(4, 'gone', 'page', 9, null, true),
			$this->menuItem(5, 'lost', 'table', 404),
			$this->menuItem(6, 'docs', 'url', null),
		]);

		$exported = $this->service->exportMenuItems($context);

		$this->assertSame(['dashboard', 'cases', 'open', 'docs'], array_column($exported, 'technicalName'));
		$this->assertSame([3, null, null, null], array_column($exported, 'targetPageId'));
		$this->assertSame([null, 'table-5', 'view-6', null], array_column($exported, 'targetUuid'));
		$this->assertSame([null, 'dashboard', null, null], array_column($exported, 'parentTechnicalName'));
	}

	public function testImportMapsPageTargetsAndTurnsAGridViewTargetIntoItsPage(): void {
		$this->permissionsService->method('canManageNodeById')->willReturnCallback(static fn (int $type, int $id): bool => $id !== 8);

		$items = $this->service->resolveMenuItems([
			['label' => 'Home', 'targetType' => 'page', 'targetPageId' => 3, 'technicalName' => 'home'],
			['label' => 'Old page', 'targetType' => 'view', 'targetUuid' => 'grid-1'],
			['label' => 'Cases', 'targetType' => 'table', 'targetUuid' => 'table-5', 'parentTechnicalName' => 'home'],
			['label' => 'Not mine', 'targetType' => 'table', 'targetUuid' => 'table-8'],
			['label' => 'Not here', 'targetType' => 'view', 'targetUuid' => 'missing'],
			['label' => 'Gone page', 'targetType' => 'page', 'targetPageId' => 4],
			['label' => 'Docs', 'targetType' => 'url', 'url' => 'https://example.org', 'parentTechnicalName' => 'skipped'],
		], self::USER, [3 => 30], ['grid-1' => 31]);

		$this->assertSame(['Home', 'Old page', 'Cases', 'Docs'], array_map(static fn ($item) => $item->getLabel(), $items));
		$this->assertSame(['page', 'page', 'table', 'url'], array_map(static fn ($item) => $item->getTargetType(), $items));
		$this->assertSame([30, 31, 5, null], array_map(static fn ($item) => $item->getTargetId(), $items));
		$this->assertSame([null, null, 0, null], array_map(static fn ($item) => $item->getParentIndex(), $items));
	}

	public function testImportGivesALaterItemWithATakenTechnicalNameADerivedOne(): void {
		$items = $this->service->resolveMenuItems([
			['label' => 'One', 'targetType' => 'url', 'url' => 'https://example.org/1', 'technicalName' => 'docs'],
			['label' => 'Two', 'targetType' => 'url', 'url' => 'https://example.org/2', 'technicalName' => 'docs'],
		], self::USER, [], []);

		$this->assertSame(['docs', null], array_map(static fn ($item) => $item->getTechnicalName(), $items));
	}

	public function testImportFillsTheStartPageOnlyWhenItWasArrangedOnTheGrid(): void {
		$saved = [];
		$this->expectPages([$this->startPage()], $saved);
		$context = $this->context([10 => ['id' => 10, 'node_id' => 5, 'node_type' => Application::NODE_TYPE_TABLE, 'permissions' => 1]]);
		$nodes = [['id' => 70, 'node_type' => Application::NODE_TYPE_TABLE, 'node_uuid' => 'table-5', 'permissions' => 1]];

		$listed = $this->service->importPages($context, new ContextScheme('App', '', '', $nodes, [
			'2' => ['id' => 2, 'page_type' => 'startpage', 'content' => ['11' => ['id' => 11, 'order' => 10, 'node_rel_id' => 70]]],
		]), self::USER);
		$this->assertSame([], $saved, 'a start page that only lists its tables keeps what the nodes gave it');
		$this->assertSame([2 => 1], $listed['pages']);

		$this->service->importPages($context, new ContextScheme('App', '', '', $nodes, [[
			'id' => 2, 'page_type' => 'startpage', 'content' => [
				['type' => 'header', 'configuration' => ['title' => '', 'showTitle' => false], 'content' => ['title' => 'Welcome'], 'grid_x' => 0, 'grid_y' => 0, 'grid_width' => 12, 'grid_height' => 2, 'node_rel_id' => null],
				['type' => 'data', 'configuration' => [], 'content' => [], 'grid_x' => 0, 'grid_y' => 2, 'grid_width' => 6, 'grid_height' => 5, 'node_rel_id' => 70],
			],
		]]), self::USER);
		$this->assertSame(['header', 'data'], array_column($saved[1], 'type'));
		$this->assertSame('Welcome', $saved[1][0]['content']['title']);
		$this->assertSame([0, 2], array_column($saved[1], 'gridY'));
		$this->assertSame(['nodeType' => Application::NODE_TYPE_TABLE, 'nodeId' => 5], $saved[1][1]['node']);
	}

	public function testImportReplacesAPageWithTheSameTechnicalNameAndAddsTheOthers(): void {
		$saved = [];
		$created = [];
		$this->expectPages([$this->startPage(), ['id' => 4, 'page_type' => 'grid', 'title' => 'Old', 'technical_name' => 'reports', 'content' => []]], $saved, $created);
		$this->pageService->expects($this->once())->method('update')->with(7, 4, 'Reports', null, self::USER);

		$result = $this->service->importPages($this->context(), new ContextScheme('App', '', '', [], [
			['id' => 20, 'page_type' => 'grid', 'title' => 'Reports', 'technical_name' => 'reports', 'content' => []],
			['id' => 21, 'page_type' => 'grid', 'title' => 'Intake', 'technical_name' => 'intake', 'content' => [
				['type' => 'text', 'configuration' => [], 'content' => ['text' => 'Hello'], 'grid_x' => 0, 'grid_y' => 0, 'grid_width' => 6, 'grid_height' => 2],
			]],
			['id' => 22, 'page_type' => 'grid', 'title' => '', 'technical_name' => 'Not valid', 'content' => []],
		]), self::USER);

		$this->assertSame([20 => 4, 21 => 101], $result['pages']);
		$this->assertSame([['Intake', 'intake']], $created);
		$this->assertSame([], $saved[4]);
		$this->assertSame('Hello', $saved[101][0]['content']['text']);
	}

	public function testImportLeavesOutDataWidgetsWhoseNodeIsNotHereOrCannotBeAdded(): void {
		$saved = [];
		$this->expectPages([$this->startPage()], $saved);
		$this->permissionsService->method('canManageNodeById')->willReturnCallback(static fn (int $type, int $id): bool => $id === 6);
		$nodes = [
			['id' => 70, 'node_type' => Application::NODE_TYPE_TABLE, 'node_uuid' => 'table-5', 'permissions' => 1],
			['id' => 71, 'node_type' => Application::NODE_TYPE_VIEW, 'node_uuid' => 'view-6', 'permissions' => 1],
			['id' => 72, 'node_type' => Application::NODE_TYPE_TABLE, 'node_uuid' => 'elsewhere', 'permissions' => 1],
		];
		$data = static fn (int $relId, int $y): array => ['type' => 'data', 'configuration' => [], 'content' => [], 'grid_x' => 0, 'grid_y' => $y, 'grid_width' => 12, 'grid_height' => 5, 'node_rel_id' => $relId];

		$this->service->importPages($this->context(), new ContextScheme('App', '', '', $nodes, [
			['id' => 20, 'page_type' => 'grid', 'title' => 'Data', 'content' => [$data(70, 0), $data(71, 5), $data(72, 10), $data(99, 15)]],
		]), self::USER);

		$this->assertSame([['nodeType' => Application::NODE_TYPE_VIEW, 'nodeId' => 6]], array_column($saved[101], 'node'));
	}

	public function testImportTurnsAGridViewOfAnOlderSchemeIntoAPage(): void {
		$saved = [];
		$created = [];
		$this->expectPages([$this->startPage()], $saved, $created);
		$this->permissionsService->method('canManageNodeById')->willReturn(true);
		$context = $this->context([10 => ['id' => 10, 'node_id' => 5, 'node_type' => Application::NODE_TYPE_TABLE, 'permissions' => 1]]);

		$result = $this->service->importPages($context, new ContextScheme('App', '', '', [], null, [], null, [[
			'uuid' => 'grid-1',
			'title' => 'Intake home',
			'grid' => [
				'widgets' => [
					['id' => 'w1', 'type' => 'header', 'configuration' => ['showTitle' => false], 'content' => ['title' => 'Welcome']],
					['id' => 'w2', 'type' => 'data', 'configuration' => [], 'content' => ['target' => ['type' => 'table', 'id' => 99, 'uuid' => 'table-5']]],
					['id' => 'w3', 'type' => 'data', 'configuration' => [], 'content' => ['target' => ['type' => 'view', 'id' => 98, 'uuid' => 'not-here']]],
					['id' => 'w4', 'type' => 'text', 'configuration' => [], 'content' => ['text' => 'Not placed']],
				],
				'layout' => [
					['widgetId' => 'w1', 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 12, 'gridHeight' => 2],
					['widgetId' => 'w2', 'gridX' => 0, 'gridY' => 2, 'gridWidth' => 8, 'gridHeight' => 5],
					['widgetId' => 'w3', 'gridX' => 8, 'gridY' => 2, 'gridWidth' => 4, 'gridHeight' => 5],
				],
			],
		]]), self::USER);

		$this->assertSame(['grid-1' => 101], $result['gridViews']);
		$this->assertSame([['Intake home', null]], $created);
		$this->assertSame(['header', 'data'], array_column($saved[101], 'type'));
		$this->assertSame(['nodeType' => Application::NODE_TYPE_TABLE, 'nodeId' => 5], $saved[101][1]['node']);
		$this->assertSame([], $saved[101][1]['content']);
		$this->assertSame([8, 5], [$saved[101][1]['gridWidth'], $saved[101][1]['gridHeight']]);
	}
}
