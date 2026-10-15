<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use OCA\Tables\Db\Context;
use OCA\Tables\Db\ContextMapper;
use OCA\Tables\Db\ContextNodeRelation;
use OCA\Tables\Db\ContextNodeRelationMapper;
use OCA\Tables\Db\Page;
use OCA\Tables\Db\PageContent;
use OCA\Tables\Db\PageContentMapper;
use OCA\Tables\Db\PageMapper;
use OCA\Tables\Db\Table;
use OCA\Tables\Db\TableMapper;
use OCA\Tables\Db\ViewMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ContextServiceTest extends TestCase {
	private ContextService $service;
	private MockObject $contextMapper;
	private MockObject $contextNodeRelMapper;
	private MockObject $pageMapper;
	private MockObject $pageContentMapper;

	protected function setUp(): void {
		parent::setUp();

		$this->contextMapper = $this->createMock(ContextMapper::class);
		$this->contextNodeRelMapper = $this->createMock(ContextNodeRelationMapper::class);
		$this->pageMapper = $this->createMock(PageMapper::class);
		$this->pageContentMapper = $this->createMock(PageContentMapper::class);
		$logger = $this->createMock(LoggerInterface::class);
		$permissionsService = $this->createMock(PermissionsService::class);
		$userManager = $this->createMock(IUserManager::class);
		$eventDispatcher = $this->createMock(IEventDispatcher::class);
		$dbConnection = $this->createMock(IDBConnection::class);
		$shareService = $this->createMock(ShareService::class);
		$navigationManager = $this->createMock(INavigationManager::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);

		$this->service = new ContextService(
			$this->contextMapper,
			$this->contextNodeRelMapper,
			$this->pageMapper,
			$this->pageContentMapper,
			$logger,
			$permissionsService,
			$userManager,
			$eventDispatcher,
			$dbConnection,
			$shareService,
			false,
			$navigationManager,
			$urlGenerator,
			$this->createMock(TableMapper::class),
			$this->createMock(ViewMapper::class),
		);
	}

	public function testUpdateReordersStartPageContentsFromSubmittedNodes(): void {
		$context = new Context();
		$context->id = 7;
		$context->setNodes([
			101 => [
				'id' => 101,
				'node_type' => 0,
				'node_id' => 11,
				'permissions' => 1,
			],
			102 => [
				'id' => 102,
				'node_type' => 0,
				'node_id' => 12,
				'permissions' => 1,
			],
		]);
		$context->setPages([
			301 => [
				'id' => 301,
				'page_type' => Page::TYPE_STARTPAGE,
				'content' => [
					501 => [
						'id' => 501,
						'node_rel_id' => 101,
						'order' => 10,
					],
					502 => [
						'id' => 502,
						'node_rel_id' => 102,
						'order' => 20,
					],
				],
			],
		]);

		$pageContentsById = [
			501 => $this->buildPageContent(501, 301, 101, 10),
			502 => $this->buildPageContent(502, 301, 102, 20),
		];
		$updatedOrders = [];

		$this->contextMapper
			->expects($this->once())
			->method('findById')
			->with(7, 'user-1')
			->willReturn($context);

		$this->pageContentMapper
			->expects($this->exactly(2))
			->method('findById')
			->willReturnCallback(static fn (int $contentId): PageContent => $pageContentsById[$contentId]);

		$this->pageContentMapper
			->expects($this->exactly(2))
			->method('update')
			->willReturnCallback(function (PageContent $pageContent) use (&$updatedOrders): PageContent {
				$updatedOrders[$pageContent->getId()] = $pageContent->getOrder();
				return $pageContent;
			});

		$this->contextMapper
			->expects($this->once())
			->method('update')
			->with($context)
			->willReturn($context);

		$updatedContext = $this->service->update(7, 'user-1', null, null, null, [
			[
				'id' => 12,
				'type' => 0,
				'permissions' => 1,
			],
			[
				'id' => 11,
				'type' => 0,
				'permissions' => 1,
			],
		]);

		self::assertCount(2, $updatedOrders);
		self::assertSame(20, $updatedOrders[501]);
		self::assertSame(10, $updatedOrders[502]);
		self::assertSame(20, $updatedContext->getPages()[301]['content'][501]['order']);
		self::assertSame(10, $updatedContext->getPages()[301]['content'][502]['order']);
	}

	public function testImportContextKeepsContentWithoutNodeAndOrdersByPosition(): void {
		$table = new Table();
		$table->id = 40;
		$table->setOwnership('user-1');

		$this->contextMapper->expects($this->once())->method('insert')
			->willReturnCallback(static function (Context $context): Context {
				$context->id = 9;
				return $context;
			});
		$this->contextNodeRelMapper->expects($this->once())->method('insert')
			->willReturnCallback(static function (ContextNodeRelation $relation): ContextNodeRelation {
				$relation->id = 70;
				return $relation;
			});
		$insertedPages = [];
		$this->pageMapper->expects($this->exactly(2))->method('insert')
			->willReturnCallback(static function (Page $page) use (&$insertedPages): Page {
				$page->id = 80 + count($insertedPages);
				$insertedPages[] = $page;
				return $page;
			});
		$inserted = [];
		$this->pageContentMapper->expects($this->exactly(3))->method('insert')
			->willReturnCallback(static function (PageContent $content) use (&$inserted): PageContent {
				$inserted[] = $content;
				return $content;
			});

		$this->service->importContext($table, [
			'name' => 'Imported',
			'nodes' => [['id' => 5, 'node_id' => 33, 'node_type' => 0, 'permissions' => 1]],
			'pages' => [
				['id' => 1, 'page_type' => Page::TYPE_STARTPAGE, 'content' => [
					['order' => 10, 'node_rel_id' => 5],
				]],
				['id' => 2, 'page_type' => Page::TYPE_GRID, 'title' => 'Overview', 'technical_name' => 'overview', 'content' => [
					['order' => 10, 'node_rel_id' => 6, 'type' => 'data', 'configuration' => null, 'grid_x' => 0, 'grid_y' => 2, 'grid_width' => 12, 'grid_height' => 5],
					['order' => 20, 'node_rel_id' => null, 'type' => 'header', 'configuration' => ['title' => ''], 'content' => ['title' => 'Hi'], 'grid_x' => 0, 'grid_y' => 0, 'grid_width' => 12, 'grid_height' => 2],
				]],
			],
		], 33);

		self::assertSame('overview', $insertedPages[1]->getTechnicalName());
		self::assertSame('Overview', $insertedPages[1]->getTitle());
		self::assertSame('dashboard', $insertedPages[0]->getTechnicalName());

		self::assertSame(70, $inserted[0]->getNodeRelId());
		self::assertSame(10, $inserted[0]->getOrder());
		self::assertNull($inserted[0]->getGridY());

		// the node of the data widget is not part of the import
		self::assertNull($inserted[1]->getNodeRelId());
		self::assertSame('data', $inserted[1]->getType());
		self::assertSame(20, $inserted[1]->getOrder());
		self::assertNull($inserted[2]->getNodeRelId());
		self::assertSame('header', $inserted[2]->getType());
		self::assertSame(10, $inserted[2]->getOrder());
		self::assertSame(['configuration' => ['title' => ''], 'content' => ['title' => 'Hi']], json_decode($inserted[2]->getConfiguration(), true));
	}

	public function testImportContextMakesInvalidOrDuplicateTechnicalNamesUnique(): void {
		$table = new Table();
		$table->id = 40;
		$table->setOwnership('user-1');
		$this->contextMapper->method('insert')->willReturnArgument(0);
		$insertedPages = [];
		$this->pageMapper->method('insert')
			->willReturnCallback(static function (Page $page) use (&$insertedPages): Page {
				$insertedPages[] = $page;
				return $page;
			});

		$this->service->importContext($table, [
			'nodes' => [['id' => 5, 'node_id' => 33, 'node_type' => 0]],
			'pages' => [
				['page_type' => Page::TYPE_GRID, 'technical_name' => 'Not Valid'],
				['page_type' => Page::TYPE_GRID, 'technical_name' => 'sales'],
				['page_type' => Page::TYPE_GRID, 'technical_name' => 'sales'],
			],
		], 33);

		self::assertSame(['not_valid', 'sales', 'sales_2'], array_map(static fn (Page $page): ?string => $page->getTechnicalName(), $insertedPages));
	}

	public function testImportContextNamesPagesWithoutTechnicalName(): void {
		$table = new Table();
		$table->id = 40;
		$table->setOwnership('user-1');
		$this->contextMapper->method('insert')->willReturnArgument(0);
		$insertedPages = [];
		$this->pageMapper->method('insert')
			->willReturnCallback(static function (Page $page) use (&$insertedPages): Page {
				$insertedPages[] = $page;
				return $page;
			});

		$this->service->importContext($table, [
			'nodes' => [['id' => 5, 'node_id' => 33, 'node_type' => 0]],
			'pages' => [
				['page_type' => Page::TYPE_GRID, 'title' => 'Open cases'],
				['page_type' => Page::TYPE_STARTPAGE],
				['page_type' => Page::TYPE_GRID, 'title' => 'Open cases'],
				['page_type' => Page::TYPE_GRID, 'technical_name' => 'open_cases'],
				['page_type' => Page::TYPE_GRID],
			],
		], 33);

		self::assertSame(
			['open_cases_2', 'dashboard', 'open_cases_3', 'open_cases', 'page'],
			array_map(static fn (Page $page): ?string => $page->getTechnicalName(), $insertedPages),
		);
	}

	public function testCreateNamesTheStartPageDashboard(): void {
		$this->contextMapper->method('insert')->willReturnCallback(static function (Context $context): Context {
			$context->id = 9;
			return $context;
		});
		$insertedPages = [];
		$this->pageMapper->expects($this->once())->method('insert')
			->willReturnCallback(static function (Page $page) use (&$insertedPages): Page {
				$page->id = 21;
				$insertedPages[] = $page;
				return $page;
			});

		$context = $this->service->create('Sales', 'icon', '', [], 'user-1', 0);

		self::assertSame(Page::TYPE_STARTPAGE, $insertedPages[0]->getPageType());
		self::assertSame('dashboard', $insertedPages[0]->getTechnicalName());
		self::assertSame('dashboard', $context->getPages()[21]['technicalName']);
	}

	public function testAddNodeRelToPageAddsToTheStartPageWithoutPosition(): void {
		$context = new Context();
		$context->setPages([
			3 => ['id' => 3, 'page_type' => Page::TYPE_GRID, 'content' => []],
			4 => ['id' => 4, 'page_type' => Page::TYPE_STARTPAGE, 'content' => []],
		]);
		$relation = new ContextNodeRelation();
		$relation->id = 12;
		$relation->setContextId(7);
		$this->contextMapper->method('findById')->with(7)->willReturn($context);
		$this->pageContentMapper->method('findByPageAndNodeRelation')->with(4, 12)->willReturn(null);
		$this->pageContentMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$content = $this->service->addNodeRelToPage($relation, 30);

		self::assertSame(4, $content->getPageId());
		self::assertSame(12, $content->getNodeRelId());
		self::assertSame(30, $content->getOrder());
		self::assertNull($content->getGridX());
		self::assertNull($content->getGridY());
	}

	private function buildPageContent(int $id, int $pageId, int $nodeRelId, int $order): PageContent {
		$pageContent = new PageContent();
		$pageContent->id = $id;
		$pageContent->setPageId($pageId);
		$pageContent->setNodeRelId($nodeRelId);
		$pageContent->setOrder($order);

		return $pageContent;
	}
}
