<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Db;

use OCA\Tables\AppInfo\Application;
use OCA\Tables\Db\Context;
use OCA\Tables\Db\ContextMapper;
use OCA\Tables\Db\ContextNodeRelation;
use OCA\Tables\Db\ContextNodeRelationMapper;
use OCA\Tables\Db\Page;
use OCA\Tables\Db\PageContent;
use OCA\Tables\Db\PageContentMapper;
use OCA\Tables\Db\PageMapper;
use OCA\Tables\Helper\GroupHelper;
use OCA\Tables\Helper\UserHelper;
use OCA\Tables\Tests\Unit\Database\DatabaseTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

class ContextMapperTest extends DatabaseTestCase {
	private const OWNER = 'context-mapper-test-owner';

	private ContextMapper $mapper;
	private ContextNodeRelationMapper $nodeRelMapper;
	private PageMapper $pageMapper;
	private PageContentMapper $pageContentMapper;
	/** @var int[] */
	private array $contextIds = [];

	protected function setUp(): void {
		parent::setUp();
		$userHelper = $this->createMock(UserHelper::class);
		$userHelper->method('getGroupIdsForUser')->willReturn([]);
		$this->mapper = new ContextMapper($this->connectionAdapter, $userHelper, $this->createMock(GroupHelper::class));
		$this->nodeRelMapper = new ContextNodeRelationMapper($this->connectionAdapter);
		$this->pageMapper = new PageMapper($this->connectionAdapter);
		$this->pageContentMapper = new PageContentMapper($this->connectionAdapter);
	}

	protected function tearDown(): void {
		foreach ($this->contextIds as $contextId) {
			foreach ($this->pageMapper->getPageIdsForContext($contextId) as $pageId) {
				$this->pageContentMapper->deleteByPageId($pageId);
				$this->pageMapper->deleteByPageId($pageId);
			}
			$this->nodeRelMapper->deleteAllByContextId($contextId);
			$qb = $this->connection->getQueryBuilder();
			$qb->delete('tables_contexts_context')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($contextId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		parent::tearDown();
	}

	public function testContextWithNodesAndContent(): void {
		$context = $this->createContext();
		$table = $this->addNode($context, Application::NODE_TYPE_TABLE, 11, Application::PERMISSION_READ);
		$view = $this->addNode($context, Application::NODE_TYPE_VIEW, 12, Application::PERMISSION_ALL);
		$page = $this->addPage($context, Page::TYPE_STARTPAGE);
		$viewContent = $this->addContent($page, $view->getId(), 10);
		$tableContent = $this->addContent($page, $table->getId(), 20);

		$found = $this->mapper->findById($context->getId(), self::OWNER);

		$this->assertSame([
			$table->getId() => ['id' => $table->getId(), 'node_id' => 11, 'node_type' => Application::NODE_TYPE_TABLE, 'permissions' => Application::PERMISSION_READ],
			$view->getId() => ['id' => $view->getId(), 'node_id' => 12, 'node_type' => Application::NODE_TYPE_VIEW, 'permissions' => Application::PERMISSION_ALL],
		], $found->getNodes());
		$pages = $found->getPages();
		$this->assertSame([$page->getId()], array_keys($pages));
		$this->assertSame(Page::TYPE_STARTPAGE, $pages[$page->getId()]['page_type']);
		$this->assertSame([$viewContent->getId(), $tableContent->getId()], array_keys($pages[$page->getId()]['content']));
		$this->assertSame(10, $pages[$page->getId()]['content'][$viewContent->getId()]['order']);
		$this->assertSame($view->getId(), $pages[$page->getId()]['content'][$viewContent->getId()]['node_rel_id']);
		$this->assertSame(20, $pages[$page->getId()]['content'][$tableContent->getId()]['order']);
		$this->assertSame($table->getId(), $pages[$page->getId()]['content'][$tableContent->getId()]['node_rel_id']);
		$this->assertSame('data', $pages[$page->getId()]['content'][$tableContent->getId()]['type']);
	}

	public function testContentWithoutNodeMatchesNoNode(): void {
		$context = $this->createContext();
		$first = $this->addNode($context, Application::NODE_TYPE_TABLE, 21);
		$second = $this->addNode($context, Application::NODE_TYPE_TABLE, 22);
		$page = $this->addPage($context, Page::TYPE_GRID);
		$header = $this->addContent($page, null, 10, 'header', 0, 0, 12, 2);
		$data = $this->addContent($page, $second->getId(), 20, 'data', 0, 2, 12, 5);

		$found = $this->mapper->findById($context->getId(), self::OWNER);

		$this->assertSame([$first->getId(), $second->getId()], array_keys($found->getNodes()));
		$content = $found->getPages()[$page->getId()]['content'];
		$this->assertSame([$header->getId(), $data->getId()], array_keys($content));
		$this->assertNull($content[$header->getId()]['node_rel_id']);
		$this->assertSame('header', $content[$header->getId()]['type']);
		$this->assertSame([0, 0, 12, 2], [$content[$header->getId()]['grid_x'], $content[$header->getId()]['grid_y'], $content[$header->getId()]['grid_width'], $content[$header->getId()]['grid_height']]);
		$this->assertSame($second->getId(), $content[$data->getId()]['node_rel_id']);
	}

	public function testNodeOnNoPageIsStillListed(): void {
		$context = $this->createContext();
		$placed = $this->addNode($context, Application::NODE_TYPE_TABLE, 31);
		$unplaced = $this->addNode($context, Application::NODE_TYPE_VIEW, 32, Application::PERMISSION_CREATE);
		$page = $this->addPage($context, Page::TYPE_STARTPAGE);
		$this->addContent($page, $placed->getId(), 10);

		$found = $this->mapper->findById($context->getId(), self::OWNER);

		$this->assertSame([$placed->getId(), $unplaced->getId()], array_keys($found->getNodes()));
		$this->assertCount(1, $found->getPages()[$page->getId()]['content']);

		$containing = $this->mapper->findAllContainingNode(Application::NODE_TYPE_VIEW, 32, self::OWNER);
		$this->assertCount(1, $containing);
		$this->assertSame([$unplaced->getId()], array_keys($containing[0]->getNodes()));
		$this->assertSame(Application::PERMISSION_CREATE, $containing[0]->getNodes()[$unplaced->getId()]['permissions']);
	}

	public function testEmptyContext(): void {
		$context = $this->createContext();

		$found = $this->mapper->findById($context->getId(), self::OWNER);

		$this->assertSame('Mapper test', $found->getName());
		$this->assertSame([], $found->getNodes());
		$this->assertSame([], $found->getPages());
		$this->assertSame([], $found->getSharing());
	}

	public function testContextWithPagesButNoContent(): void {
		$context = $this->createContext();
		$node = $this->addNode($context, Application::NODE_TYPE_TABLE, 41);
		$startPage = $this->addPage($context, Page::TYPE_STARTPAGE);
		$gridPage = $this->addPage($context, Page::TYPE_GRID, 'Overview', 'overview');

		$found = $this->mapper->findById($context->getId(), self::OWNER);

		$this->assertSame([$node->getId()], array_keys($found->getNodes()));
		$pages = $found->getPages();
		$this->assertSame([$startPage->getId(), $gridPage->getId()], array_keys($pages));
		$this->assertSame([], $pages[$startPage->getId()]['content']);
		$this->assertSame([], $pages[$gridPage->getId()]['content']);
		$this->assertNull($pages[$startPage->getId()]['title']);
		$this->assertSame('Overview', $pages[$gridPage->getId()]['title']);
		$this->assertSame('overview', $pages[$gridPage->getId()]['technical_name']);
		$this->assertSame('dashboard', $pages[$startPage->getId()]['technical_name']);
	}

	public function testTechnicalNameIsUniqueWithinAnApplication(): void {
		$context = $this->createContext();
		$other = $this->createContext();
		$this->addPage($context, Page::TYPE_GRID, 'Overview', 'overview');
		$this->addPage($other, Page::TYPE_GRID, 'Overview', 'overview');

		$this->expectException(\OCP\DB\Exception::class);
		$this->addPage($context, Page::TYPE_GRID, 'Overview again', 'overview');
	}

	public function testFindAllReturnsEveryContextOnce(): void {
		$withContent = $this->createContext();
		$node = $this->addNode($withContent, Application::NODE_TYPE_TABLE, 51);
		$page = $this->addPage($withContent, Page::TYPE_STARTPAGE);
		$this->addContent($page, $node->getId(), 10);
		$this->addContent($page, null, 20, 'text');
		$empty = $this->createContext();

		$found = array_values(array_filter(
			$this->mapper->findAll(self::OWNER),
			fn (Context $context): bool => in_array($context->getId(), [$withContent->getId(), $empty->getId()], true),
		));

		$this->assertSame([$withContent->getId(), $empty->getId()], array_map(fn (Context $context): int => $context->getId(), $found));
		$this->assertSame([$node->getId()], array_keys($found[0]->getNodes()));
		$this->assertCount(2, $found[0]->getPages()[$page->getId()]['content']);
		$this->assertSame([], $found[1]->getNodes());
	}

	public function testFindAllContainingNodeSkipsContextsWithoutIt(): void {
		$context = $this->createContext();
		$this->addNode($context, Application::NODE_TYPE_TABLE, 61);

		$this->assertSame([], $this->mapper->findAllContainingNode(Application::NODE_TYPE_TABLE, 62, self::OWNER));
	}

	private function createContext(): Context {
		$context = new Context();
		$context->setName('Mapper test');
		$context->setIcon('tables');
		$context->setDescription('');
		$context->setOwnerId(self::OWNER);
		$context->setOwnerType(Application::OWNER_TYPE_USER);
		$context = $this->mapper->insert($context);
		$this->contextIds[] = $context->getId();
		return $context;
	}

	private function addNode(Context $context, int $nodeType, int $nodeId, int $permissions = Application::PERMISSION_READ): ContextNodeRelation {
		$relation = new ContextNodeRelation();
		$relation->setContextId($context->getId());
		$relation->setNodeType($nodeType);
		$relation->setNodeId($nodeId);
		$relation->setPermissions($permissions);
		return $this->nodeRelMapper->insert($relation);
	}

	private function addPage(Context $context, string $type, ?string $title = null, ?string $technicalName = null): Page {
		$page = new Page();
		$page->setContextId($context->getId());
		$page->setPageType($type);
		$page->setTitle($title);
		$page->setTechnicalName($technicalName ?? ($type === Page::TYPE_STARTPAGE ? 'dashboard' : 'page_' . $type));
		return $this->pageMapper->insert($page);
	}

	private function addContent(Page $page, ?int $nodeRelId, int $order, ?string $type = null, ?int $x = null, ?int $y = null, ?int $width = null, ?int $height = null): PageContent {
		$content = new PageContent();
		$content->setPageId($page->getId());
		$content->setNodeRelId($nodeRelId);
		$content->setOrder($order);
		if ($type !== null) {
			$content->setType($type);
		}
		$content->setGridX($x);
		$content->setGridY($y);
		$content->setGridWidth($width);
		$content->setGridHeight($height);
		return $this->pageContentMapper->insert($content);
	}
}
