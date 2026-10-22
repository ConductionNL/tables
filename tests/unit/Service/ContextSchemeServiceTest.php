<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\AppInfo\Application;
use OCA\Tables\Db\Context;
use OCA\Tables\Db\Table;
use OCA\Tables\Db\TableMapper;
use OCA\Tables\Db\View;
use OCA\Tables\Db\ViewMapper;
use OCA\Tables\Model\MenuItemInput;
use OCA\Tables\Service\ContextSchemeService;
use OCA\Tables\Service\PermissionsService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ContextSchemeServiceTest extends TestCase {
	private const USER = 'importer';

	private ContextSchemeService $service;
	private PermissionsService&MockObject $permissionsService;

	protected function setUp(): void {
		parent::setUp();
		$tableMapper = $this->createMock(TableMapper::class);
		$viewMapper = $this->createMock(ViewMapper::class);
		$this->permissionsService = $this->createMock(PermissionsService::class);
		$this->service = new ContextSchemeService(
			$tableMapper,
			$viewMapper,
			$this->permissionsService,
			$this->createMock(LoggerInterface::class),
		);

		// Tables and views exist here under the uuids "table-<id>" and "view-<id>"
		$tableMapper->method('findByUuid')->willReturnCallback(static function (string $uuid): Table {
			if (!str_starts_with($uuid, 'table-')) {
				throw new DoesNotExistException('no table ' . $uuid);
			}
			$table = new Table();
			$table->setId((int)substr($uuid, 6));
			return $table;
		});
		$viewMapper->method('findByUuid')->willReturnCallback(static function (string $uuid): ?View {
			if (!str_starts_with($uuid, 'view-')) {
				return null;
			}
			$view = new View();
			$view->setId((int)substr($uuid, 5));
			return $view;
		});
		$tableMapper->method('find')->willReturnCallback(static function (int $id): Table {
			if ($id === 404) {
				throw new DoesNotExistException('gone');
			}
			$table = new Table();
			$table->setId($id);
			$table->setUuid('table-' . $id);
			return $table;
		});
		$viewMapper->method('find')->willReturnCallback(static function (int $id): View {
			$view = new View();
			$view->setId($id);
			$view->setUuid('view-' . $id);
			return $view;
		});
	}

	private function context(array $menuItems, array $pages): Context {
		$context = new Context();
		$context->id = 7;
		$context->setNodes([]);
		$context->setPages($pages);
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

	public function testExportNamesTablesAndViewsByUuidAndPagesByTechnicalNameAndSkipsBrokenItems(): void {
		$context = $this->context([
			$this->menuItem(1, 'dashboard', 'page', 3),
			$this->menuItem(2, 'cases', 'table', 5, 1),
			$this->menuItem(3, 'open', 'view', 6),
			$this->menuItem(4, 'gone', 'page', 9, null, true),
			$this->menuItem(5, 'lost', 'table', 404),
			$this->menuItem(6, 'docs', 'url', null),
			$this->menuItem(7, 'elsewhere', 'page', 12),
		], [
			3 => ['id' => 3, 'page_type' => 'startpage', 'technical_name' => 'dashboard', 'content' => []],
		]);

		$exported = $this->service->exportMenuItems($context);

		$this->assertSame(['dashboard', 'cases', 'open', 'docs'], array_column($exported, 'technicalName'));
		$this->assertSame(['dashboard', null, null, null], array_column($exported, 'targetPageTechnicalName'));
		$this->assertSame([null, 'table-5', 'view-6', null], array_column($exported, 'targetUuid'));
		$this->assertSame([null, 'dashboard', null, null], array_column($exported, 'parentTechnicalName'));
	}

	public function testImportMapsPageTargetsByTechnicalNameAndTurnsAGridViewTargetIntoItsPage(): void {
		$this->permissionsService->method('canManageNodeById')->willReturnCallback(static fn (int $type, int $id): bool => $id !== 8);

		$items = $this->service->resolveMenuItems([
			['label' => 'Home', 'targetType' => 'page', 'targetPageTechnicalName' => 'dashboard', 'technicalName' => 'home'],
			['label' => 'Old page', 'targetType' => 'view', 'targetUuid' => 'grid-1'],
			['label' => 'Cases', 'targetType' => 'table', 'targetUuid' => 'table-5', 'parentTechnicalName' => 'home'],
			['label' => 'Not mine', 'targetType' => 'table', 'targetUuid' => 'table-8'],
			['label' => 'Not here', 'targetType' => 'view', 'targetUuid' => 'missing'],
			['label' => 'Gone page', 'targetType' => 'page', 'targetPageTechnicalName' => 'archive'],
			['label' => 'Docs', 'targetType' => 'url', 'url' => 'https://example.org', 'parentTechnicalName' => 'skipped'],
		], self::USER, ['dashboard' => 30, 'old_page' => 31], ['grid-1' => 'old_page']);

		$this->assertSame(['Home', 'Old page', 'Cases', 'Docs'], array_map(static fn (MenuItemInput $item) => $item->getLabel(), $items));
		$this->assertSame(['page', 'page', 'table', 'url'], array_map(static fn (MenuItemInput $item) => $item->getTargetType(), $items));
		$this->assertSame([30, 31, 5, null], array_map(static fn (MenuItemInput $item) => $item->getTargetId(), $items));
		$this->assertSame([null, null, 0, null], array_map(static fn (MenuItemInput $item) => $item->getParentIndex(), $items));
	}

	public function testImportGivesALaterItemWithATakenTechnicalNameADerivedOne(): void {
		$items = $this->service->resolveMenuItems([
			['label' => 'One', 'targetType' => 'url', 'url' => 'https://example.org/1', 'technicalName' => 'docs'],
			['label' => 'Two', 'targetType' => 'url', 'url' => 'https://example.org/2', 'technicalName' => 'docs'],
		], self::USER, [], []);

		$this->assertSame(['docs', null], array_map(static fn (MenuItemInput $item) => $item->getTechnicalName(), $items));
	}

	public function testAGridViewBecomesAPageWithItsWidgetsInPlace(): void {
		$this->permissionsService->method('canManageNodeById')->willReturnCallback(static fn (int $type, int $id): bool => $id !== 8);
		$nodes = [['id' => 70, 'node_type' => Application::NODE_TYPE_TABLE, 'node_uuid' => 'table-5', 'permissions' => 1]];

		$converted = $this->service->pagesFromGridViews([[
			'uuid' => 'grid-1',
			'title' => 'Intake home',
			'emoji' => '🏠',
			'grid' => [
				'widgets' => [
					['id' => 'w1', 'type' => 'header', 'configuration' => ['title' => '', 'showTitle' => false], 'content' => ['title' => 'Welcome', 'subtitle' => 'Start here']],
					['id' => 'w2', 'type' => 'data', 'configuration' => ['title' => 'Cases', 'showTitle' => true], 'content' => ['target' => ['type' => 'table', 'id' => 1, 'uuid' => 'table-5']]],
					['id' => 'w3', 'type' => 'data', 'configuration' => [], 'content' => ['target' => ['type' => 'view', 'uuid' => 'view-6']]],
					['id' => 'w4', 'type' => 'data', 'configuration' => [], 'content' => ['target' => ['type' => 'table', 'uuid' => 'table-8']]],
					['id' => 'w5', 'type' => 'text', 'configuration' => ['title' => 'About'], 'content' => ['text' => 'Hello']],
					['id' => 'unplaced', 'type' => 'text', 'content' => ['text' => 'Not in the layout']],
				],
				'layout' => [
					['widgetId' => 'w1', 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 12, 'gridHeight' => 2],
					['widgetId' => 'w2', 'gridX' => 0, 'gridY' => 2, 'gridWidth' => 6, 'gridHeight' => 5],
					['widgetId' => 'w3', 'gridX' => 6, 'gridY' => 2, 'gridWidth' => 6, 'gridHeight' => 5],
					['widgetId' => 'w4', 'gridX' => 0, 'gridY' => 7, 'gridWidth' => 12, 'gridHeight' => 5],
					['widgetId' => 'w5', 'gridX' => 0, 'gridY' => 12, 'gridWidth' => 6, 'gridHeight' => 2],
				],
			],
		]], $nodes, ['intake_home' => true], self::USER);

		$page = $converted['pages'][0];
		// The scheme already has a page named after the title, so the grid view's page gets the next name
		$this->assertSame('intake_home_2', $page['technical_name']);
		$this->assertSame(['grid-1' => 'intake_home_2'], $converted['technicalNames']);
		$this->assertSame(['grid', 'Intake home', '🏠'], [$page['page_type'], $page['title'], $page['emoji']]);
		$this->assertSame(['header', 'data', 'data', 'data', 'text'], array_column($page['widgets'], 'type'));
		$this->assertSame(['heading' => 'Welcome', 'subheading' => 'Start here'], $page['widgets'][0]['settings']);
		$this->assertSame([null, 'Cases', null, null, 'About'], array_column($page['widgets'], 'title'));
		$this->assertSame([6, 2, 6, 5], [$page['widgets'][2]['gridX'], $page['widgets'][2]['gridY'], $page['widgets'][2]['gridWidth'], $page['widgets'][2]['gridHeight']]);
		// A scheme node shows the table; the view is added as a node; the table the importer cannot manage gets no source
		$this->assertSame(['nodeRelId' => 70], $page['widgets'][1]['source']);
		$this->assertSame(['nodeRelId' => -1], $page['widgets'][2]['source']);
		$this->assertNull($page['widgets'][3]['source']);
		$this->assertArrayNotHasKey('source', $page['widgets'][4]);
		$this->assertSame([['id' => -1, 'node_type' => Application::NODE_TYPE_VIEW, 'node_uuid' => 'view-6', 'permissions' => Application::PERMISSION_READ]], $converted['nodes']);
	}
}
