<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\AppInfo\Application;
use OCA\Tables\Db\Context;
use OCA\Tables\Db\ContextMapper;
use OCA\Tables\Db\ContextNodeRelation;
use OCA\Tables\Db\ContextNodeRelationMapper;
use OCA\Tables\Db\Page;
use OCA\Tables\Db\PageContentMapper;
use OCA\Tables\Db\PageMapper;
use OCA\Tables\Db\Table;
use OCA\Tables\Db\TableMapper;
use OCA\Tables\Db\ViewMapper;
use OCA\Tables\Db\WidgetMapper;
use OCA\Tables\Db\WidgetSourceMapper;
use OCA\Tables\Helper\GroupHelper;
use OCA\Tables\Helper\UserHelper;
use OCA\Tables\Service\ContextService;
use OCA\Tables\Service\GridWidgetTypes;
use OCA\Tables\Service\PermissionsService;
use OCA\Tables\Service\ShareService;
use OCA\Tables\Service\WidgetService;
use OCA\Tables\Tests\Unit\Database\DatabaseTestCase;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Runs against the database: a grid page with widgets is exported and imported again,
 * and removing or deleting its parts cleans up the widget rows.
 */
class ContextPagesRoundTripTest extends DatabaseTestCase {
	private const OWNER = 'context-pages-round-trip-owner';

	private ContextService $service;
	private ContextMapper $contextMapper;
	private ContextNodeRelationMapper $nodeRelMapper;
	private PageMapper $pageMapper;
	private WidgetService $widgetService;
	private TableMapper $tableMapper;
	/** @var int[] */
	private array $contextIds = [];

	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$userHelper = $this->createMock(UserHelper::class);
		$userHelper->method('getGroupIdsForUser')->willReturn([]);
		$this->contextMapper = new ContextMapper($this->connectionAdapter, $userHelper, $this->createMock(GroupHelper::class));
		$this->nodeRelMapper = new ContextNodeRelationMapper($this->connectionAdapter);
		$this->pageMapper = new PageMapper($this->connectionAdapter);
		$this->widgetService = new WidgetService(
			new WidgetMapper($this->connectionAdapter),
			new WidgetSourceMapper($this->connectionAdapter),
		);
		$this->service = new ContextService(
			$this->contextMapper,
			$this->nodeRelMapper,
			$this->pageMapper,
			new PageContentMapper($this->connectionAdapter),
			$this->createMock(LoggerInterface::class),
			$this->createMock(PermissionsService::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IEventDispatcher::class),
			$this->connectionAdapter,
			$this->createMock(ShareService::class),
			false,
			$this->createMock(INavigationManager::class),
			$this->createMock(IURLGenerator::class),
			$this->tableMapper = $this->createMock(TableMapper::class),
			$this->createMock(ViewMapper::class),
			$this->widgetService,
			new GridWidgetTypes($l10n),
		);
	}

	protected function tearDown(): void {
		foreach ($this->contextIds as $contextId) {
			try {
				$this->service->delete($contextId, self::OWNER);
			} catch (\Throwable) {
			}
		}
		parent::tearDown();
	}

	public function testExportedWidgetsAreImportedWithTheirNode(): void {
		[$context, $relation, $gridPageId] = $this->createContextWithGridPage();
		$table = new Table();
		$table->id = 4242;
		$table->setOwnership(self::OWNER);

		$exported = json_decode(json_encode(array_merge(
			$this->contextMapper->findById($context->getId(), self::OWNER)->jsonSerialize(),
			['pages' => $this->service->getPagesWithWidgets($this->contextMapper->findById($context->getId(), self::OWNER))],
		)), true);
		$exported['nodes'][$relation->getId()]['node_id'] = 4242;

		$imported = $this->service->importContext($table, $exported, 4242);
		$this->assertNotNull($imported);
		$this->contextIds[] = $imported->getId();

		$importedContext = $this->contextMapper->findById($imported->getId(), self::OWNER);
		$importedRelationId = array_key_first($importedContext->getNodes());
		$importedPages = $this->service->getPagesWithWidgets($importedContext);
		$gridPages = array_values(array_filter($importedPages, static fn (array $page): bool => $page['page_type'] === Page::TYPE_GRID));
		$this->assertCount(1, $gridPages);
		$this->assertSame(['Overview', '📊', 'At a glance', 'overview'], [$gridPages[0]['title'], $gridPages[0]['emoji'], $gridPages[0]['description'], $gridPages[0]['technical_name']]);

		$original = $this->service->getPagesWithWidgets($this->contextMapper->findById($context->getId(), self::OWNER))[$gridPageId]['widgets'];
		$withoutIds = static fn (array $widgets): array => array_map(static function (array $widget): array {
			unset($widget['id']);
			return $widget;
		}, $widgets);
		$expected = $withoutIds($original);
		$expected[1]['source'] = ['nodeRelId' => $importedRelationId];
		$this->assertNotSame($relation->getId(), $importedRelationId);
		$this->assertSame($expected, $withoutIds($gridPages[0]['widgets']));
	}

	public function testRemovingTheNodeKeepsTheWidgetAndDeletingTheContextRemovesIt(): void {
		[$context, $relation, $gridPageId] = $this->createContextWithGridPage();

		$this->service->removeNodeFromContextAndPages($relation->getId());

		$widgets = $this->widgetService->findByPageIds([$gridPageId])[$gridPageId];
		$this->assertCount(2, $widgets);
		$this->assertNull($widgets[1]['source']);
		$this->assertSame('data', $widgets[1]['type']);

		$this->service->delete($context->getId(), self::OWNER);

		$this->assertSame([], $this->widgetService->findByPageIds([$gridPageId]));
		$this->assertSame([], $this->pageMapper->getPageIdsForContext($context->getId()));
	}

	public function testImportedPagesAreNamedUniquelyWithinTheApplication(): void {
		$table = new Table();
		$table->id = 4343;
		$table->setOwnership(self::OWNER);

		$imported = $this->service->importContext($table, [
			'name' => 'Named',
			'nodes' => [['id' => 1, 'node_id' => 4343, 'node_type' => Application::NODE_TYPE_TABLE, 'permissions' => 1]],
			'pages' => [
				['id' => 1, 'page_type' => Page::TYPE_STARTPAGE, 'content' => []],
				['id' => 2, 'page_type' => Page::TYPE_GRID, 'title' => 'Sales board', 'content' => []],
				['id' => 3, 'page_type' => Page::TYPE_GRID, 'title' => 'Sales board', 'technical_name' => 'sales_board', 'content' => []],
			],
		], 4343);
		$this->contextIds[] = $imported->getId();

		$pages = array_values($this->contextMapper->findById($imported->getId(), self::OWNER)->getPages());
		usort($pages, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
		$this->assertSame(['dashboard', 'sales_board_2', 'sales_board'], array_column($pages, 'technical_name'));
	}

	public function testASchemeUpdatesThePageWithTheSameTechnicalNameAndAddsTheOthers(): void {
		[$context, $relation, $gridPageId] = $this->createContextWithGridPage();
		$this->tableMapper->method('findByUuid')->with('table-uuid')->willReturnCallback(static function (): Table {
			$table = new Table();
			$table->id = 4141;
			return $table;
		});

		$this->service->importSchemePages($context->getId(), [
			['id' => 900, 'node_type' => Application::NODE_TYPE_TABLE, 'node_uuid' => 'table-uuid', 'permissions' => 1],
		], [
			['page_type' => Page::TYPE_GRID, 'technical_name' => 'overview', 'title' => 'Renamed', 'emoji' => null, 'description' => null, 'widgets' => [
				['type' => 'data', 'title' => 'Tasks', 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 12, 'gridHeight' => 5, 'settings' => [], 'source' => ['nodeRelId' => 900]],
			]],
			['page_type' => Page::TYPE_GRID, 'technical_name' => 'planning', 'title' => 'Planning', 'widgets' => [
				['type' => 'text', 'title' => null, 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 6, 'gridHeight' => 2, 'settings' => ['text' => 'Next'], 'source' => null],
			]],
		]);

		$pages = $this->service->getPagesWithWidgets($this->contextMapper->findById($context->getId(), self::OWNER));
		$this->assertCount(3, $pages);
		$this->assertSame('Renamed', $pages[$gridPageId]['title']);
		$this->assertCount(1, $pages[$gridPageId]['widgets']);
		$this->assertSame(['nodeRelId' => $relation->getId()], $pages[$gridPageId]['widgets'][0]['source']);
		$planning = array_values(array_filter($pages, static fn (array $page): bool => $page['technical_name'] === 'planning'));
		$this->assertCount(1, $planning);
		$this->assertSame(Page::TYPE_GRID, $planning[0]['page_type']);
		$this->assertSame(['text' => 'Next'], $planning[0]['widgets'][0]['settings']);
	}

	/**
	 * @return array{0: Context, 1: ContextNodeRelation, 2: int}
	 */
	private function createContextWithGridPage(): array {
		$context = $this->service->create('Projects', 'tables', '', [], self::OWNER, Application::OWNER_TYPE_USER);
		$this->contextIds[] = $context->getId();
		$relation = $this->service->addNodeToContext($context, 4141, Application::NODE_TYPE_TABLE, Application::PERMISSION_READ);
		$this->service->addNodeRelToPage($relation);

		$page = new Page();
		$page->setContextId($context->getId());
		$page->setPageType(Page::TYPE_GRID);
		$page->setTitle('Overview');
		$page->setEmoji('📊');
		$page->setDescription('At a glance');
		$page->setTechnicalName('overview');
		$page = $this->pageMapper->insert($page);

		$this->widgetService->insert($page->getId(), 'header', 'Welcome', ['gridX' => 0, 'gridY' => 0, 'gridWidth' => 12, 'gridHeight' => 2], ['heading' => 'Projects', 'subheading' => '', 'textAlign' => 'center', 'backgroundColor' => '#123456', 'textColor' => ''], null);
		$this->widgetService->insert($page->getId(), 'data', null, ['gridX' => 0, 'gridY' => 2, 'gridWidth' => 12, 'gridHeight' => 5], [], $relation->getId());

		return [$context, $relation, $page->getId()];
	}
}
