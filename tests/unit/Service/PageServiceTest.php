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
use OCA\Tables\Db\Page;
use OCA\Tables\Db\PageContent;
use OCA\Tables\Db\PageContentMapper;
use OCA\Tables\Db\PageMapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\Service\ContextService;
use OCA\Tables\Service\GridWidgetTypes;
use OCA\Tables\Service\PageService;
use OCA\Tables\Service\PermissionsService;
use OCA\Tables\Service\WidgetService;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PageServiceTest extends TestCase {
	private const START_PAGE = 1;
	private const GRID_PAGE = 2;
	private const NODE_REL = 20;

	private PageService $service;
	private Context $context;
	private PageMapper&MockObject $pageMapper;
	private PageContentMapper&MockObject $pageContentMapper;
	private ContextService&MockObject $contextService;
	private WidgetService&MockObject $widgetService;
	private PermissionsService&MockObject $permissionsService;

	protected function setUp(): void {
		parent::setUp();
		$this->context = new Context();
		$this->context->id = 7;
		$this->context->setNodes([self::NODE_REL => ['id' => self::NODE_REL, 'node_id' => 3, 'node_type' => Application::NODE_TYPE_TABLE, 'permissions' => 1]]);
		$this->context->setPages([
			self::START_PAGE => ['id' => self::START_PAGE, 'page_type' => Page::TYPE_STARTPAGE, 'title' => null, 'emoji' => null, 'description' => null, 'content' => []],
			self::GRID_PAGE => ['id' => self::GRID_PAGE, 'page_type' => Page::TYPE_GRID, 'title' => 'Overview', 'emoji' => '📊', 'description' => null, 'content' => []],
		]);
		$contextMapper = $this->createMock(ContextMapper::class);
		$contextMapper->method('findById')->willReturnCallback(function (int $contextId): Context {
			if ($contextId !== 7) {
				throw new NotFoundError('Context does not exist');
			}
			return $this->context;
		});
		$this->pageMapper = $this->createMock(PageMapper::class);
		$this->pageContentMapper = $this->createMock(PageContentMapper::class);
		$this->contextService = $this->createMock(ContextService::class);
		$this->widgetService = $this->createMock(WidgetService::class);
		$this->widgetService->method('findIdsByPageId')->willReturnMap([[self::GRID_PAGE, [31, 32]], [self::START_PAGE, []]]);
		$this->permissionsService = $this->createMock(PermissionsService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->service = new PageService(
			$contextMapper,
			$this->pageMapper,
			$this->pageContentMapper,
			$this->contextService,
			$this->widgetService,
			$this->permissionsService,
			new GridWidgetTypes($l10n),
			$this->createMock(IDBConnection::class),
		);
	}

	public function testFindReturnsThePageWithItsWidgets(): void {
		$widget = ['id' => 31, 'type' => 'text', 'title' => null, 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 6, 'gridHeight' => 2, 'settings' => ['text' => 'Hi'], 'source' => null];
		$this->widgetService->method('findByPageIds')->with([self::GRID_PAGE])->willReturn([self::GRID_PAGE => [$widget]]);

		$this->assertSame([
			'id' => self::GRID_PAGE,
			'contextId' => 7,
			'pageType' => Page::TYPE_GRID,
			'title' => 'Overview',
			'emoji' => '📊',
			'description' => null,
			'widgets' => [$widget],
		], $this->service->find(7, self::GRID_PAGE, 'alice'));
	}

	public function testFindRejectsAPageOfAnotherApplication(): void {
		$this->expectException(NotFoundError::class);
		$this->service->find(7, 99, 'alice');
	}

	public function testCreateInsertsAGridPage(): void {
		$this->permissionsService->method('canManageContextById')->with(7, 'alice')->willReturn(true);
		$inserted = null;
		$this->pageMapper->expects($this->once())->method('insert')->willReturnCallback(function (Page $page) use (&$inserted): Page {
			$page->id = self::GRID_PAGE;
			$inserted = $page;
			return $page;
		});

		$this->service->create(7, '  Overview ', ' 📊 ', '', 'alice');

		$this->assertSame([7, Page::TYPE_GRID, 'Overview', '📊', null], [$inserted->getContextId(), $inserted->getPageType(), $inserted->getTitle(), $inserted->getEmoji(), $inserted->getDescription()]);
	}

	public function testCreateNeedsManagePermission(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(false);
		$this->pageMapper->expects($this->never())->method('insert');

		$this->expectException(PermissionError::class);
		$this->service->create(7, 'Overview', null, null, 'bob');
	}

	public function testCreateNeedsATitle(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$this->pageMapper->expects($this->never())->method('insert');

		$this->expectException(BadRequestError::class);
		$this->service->create(7, '   ', null, null, 'alice');
	}

	public function testUpdateSyncsWidgetsById(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$synced = null;
		$this->widgetService->expects($this->once())->method('syncPage')->willReturnCallback(function (int $pageId, array $rows) use (&$synced): void {
			$this->assertSame(self::GRID_PAGE, $pageId);
			$synced = $rows;
		});
		$this->pageMapper->expects($this->never())->method('update');

		$this->service->update(7, self::GRID_PAGE, null, null, null, [
			['id' => 31, 'type' => 'header', 'title' => ' Intro ', 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 12, 'gridHeight' => 2, 'settings' => ['heading' => 'Welcome']],
			['type' => 'data', 'gridX' => 0, 'gridY' => 2, 'gridWidth' => 6, 'gridHeight' => 5, 'source' => ['nodeRelId' => self::NODE_REL]],
			['type' => 'data', 'gridX' => 6, 'gridY' => 2, 'gridWidth' => 6, 'gridHeight' => 5, 'source' => ['nodeType' => Application::NODE_TYPE_TABLE, 'nodeId' => 3]],
			['type' => 'data', 'gridX' => 0, 'gridY' => 7, 'gridWidth' => 12, 'gridHeight' => 5, 'source' => null],
		], 'alice');

		$this->assertSame([
			['id' => 31, 'type' => 'header', 'title' => 'Intro', 'position' => ['gridX' => 0, 'gridY' => 0, 'gridWidth' => 12, 'gridHeight' => 2], 'settings' => ['heading' => 'Welcome', 'subheading' => '', 'textAlign' => 'left', 'backgroundColor' => '', 'textColor' => ''], 'nodeRelId' => null],
			['id' => null, 'type' => 'data', 'title' => null, 'position' => ['gridX' => 0, 'gridY' => 2, 'gridWidth' => 6, 'gridHeight' => 5], 'settings' => [], 'nodeRelId' => self::NODE_REL],
			['id' => null, 'type' => 'data', 'title' => null, 'position' => ['gridX' => 6, 'gridY' => 2, 'gridWidth' => 6, 'gridHeight' => 5], 'settings' => [], 'nodeRelId' => self::NODE_REL],
			['id' => null, 'type' => 'data', 'title' => null, 'position' => ['gridX' => 0, 'gridY' => 7, 'gridWidth' => 12, 'gridHeight' => 5], 'settings' => [], 'nodeRelId' => null],
		], $synced);
	}

	public function testUpdateAddsATableTheUserManagesToTheApplication(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$this->permissionsService->expects($this->once())->method('canManageNodeById')->with(Application::NODE_TYPE_VIEW, 8, 'alice')->willReturn(true);
		$relation = new ContextNodeRelation();
		$relation->id = 55;
		$this->contextService->expects($this->once())->method('addNodeToContextAndStartpage')
			->with(7, 8, Application::NODE_TYPE_VIEW, Application::PERMISSION_READ, 100, 'alice')
			->willReturn([$relation, new PageContent()]);
		$this->widgetService->expects($this->once())->method('syncPage')->willReturnCallback(function (int $pageId, array $rows): void {
			$this->assertSame([55, 55], array_column($rows, 'nodeRelId'));
			$this->assertArrayNotHasKey('nodeKey', $rows[0]);
		});

		$this->service->update(7, self::GRID_PAGE, null, null, null, [
			['type' => 'data', 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 6, 'gridHeight' => 5, 'source' => ['nodeType' => Application::NODE_TYPE_VIEW, 'nodeId' => 8]],
			['type' => 'data', 'gridX' => 6, 'gridY' => 0, 'gridWidth' => 6, 'gridHeight' => 5, 'source' => ['nodeType' => Application::NODE_TYPE_VIEW, 'nodeId' => 8]],
		], 'alice');
	}

	public function testUpdateRefusesATableTheUserDoesNotManage(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$this->permissionsService->method('canManageNodeById')->willReturn(false);
		$this->contextService->expects($this->never())->method('addNodeToContextAndStartpage');
		$this->widgetService->expects($this->never())->method('syncPage');

		$this->expectException(PermissionError::class);
		$this->service->update(7, self::GRID_PAGE, null, null, null, [
			['type' => 'data', 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 6, 'gridHeight' => 5, 'source' => ['nodeType' => Application::NODE_TYPE_TABLE, 'nodeId' => 9]],
		], 'alice');
	}

	public function testUpdateNeedsManagePermission(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(false);
		$this->widgetService->expects($this->never())->method('syncPage');
		$this->pageMapper->expects($this->never())->method('update');

		$this->expectException(PermissionError::class);
		$this->service->update(7, self::GRID_PAGE, 'New', null, null, [], 'bob');
	}

	public function testUpdateChangesPageFields(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$stored = new Page();
		$stored->id = self::GRID_PAGE;
		$stored->setTitle('Overview');
		$stored->setEmoji('📊');
		$stored->resetUpdatedFields();
		$this->pageMapper->method('findById')->with(self::GRID_PAGE)->willReturn($stored);
		$updated = null;
		$this->pageMapper->expects($this->once())->method('update')->willReturnCallback(function (Page $page) use (&$updated): Page {
			$updated = $page;
			return $page;
		});
		$this->widgetService->expects($this->never())->method('syncPage');

		$this->service->update(7, self::GRID_PAGE, 'Planning', '', 'What is next', null, 'alice');

		$this->assertSame([self::GRID_PAGE, 'Planning', null, 'What is next'], [$updated->getId(), $updated->getTitle(), $updated->getEmoji(), $updated->getDescription()]);
		$this->assertSame(['title', 'emoji', 'description'], array_keys($updated->getUpdatedFields()));
	}

	public static function invalidWidgets(): array {
		$at = ['gridX' => 0, 'gridY' => 0, 'gridWidth' => 6, 'gridHeight' => 2];
		return [
			'not an object' => [['x'], 'Widget 0 must be an object'],
			'no type' => [[$at], 'Widget 0 needs a type'],
			'unknown type' => [[['type' => 'chart'] + $at], 'Unknown widget type'],
			'id of another page' => [[['id' => 99, 'type' => 'text'] + $at], 'Widget 0 has the id of no other widget on this page'],
			'id twice' => [[['id' => 31, 'type' => 'text'] + $at, ['id' => 31, 'type' => 'text'] + $at], 'Widget 1 has the id'],
			'invalid setting' => [[['type' => 'header', 'settings' => ['heading' => '']] + $at], 'header.heading is required'],
			'outside the grid' => [[['type' => 'text', 'gridX' => 8, 'gridY' => 0, 'gridWidth' => 6, 'gridHeight' => 2]], 'does not fit in 12 columns'],
			'no position' => [[['type' => 'text']], 'Widget 0 needs gridX'],
			'source on a header' => [[['type' => 'header', 'settings' => ['heading' => 'A'], 'source' => ['nodeRelId' => self::NODE_REL]] + $at], 'shows no table or view'],
			'node of another application' => [[['type' => 'data', 'source' => ['nodeRelId' => 21]] + $at], 'not part of this application'],
			'invalid node' => [[['type' => 'data', 'source' => ['nodeType' => 5, 'nodeId' => 1]] + $at], 'needs a nodeRelId of this application'],
			'title not text' => [[['type' => 'text', 'title' => 5] + $at], 'The title of widget 0'],
		];
	}

	/**
	 * @dataProvider invalidWidgets
	 */
	public function testUpdateRejectsInvalidWidgets(array $widgets, string $message): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$this->widgetService->expects($this->never())->method('syncPage');

		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage($message);
		$this->service->update(7, self::GRID_PAGE, null, null, null, $widgets, 'alice');
	}

	public function testTheStartPageHoldsNoWidgets(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);

		$this->expectException(BadRequestError::class);
		$this->service->update(7, self::START_PAGE, null, null, null, [], 'alice');
	}

	public function testDeleteRemovesAGridPageWithItsWidgets(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$this->widgetService->method('findByPageIds')->willReturn([]);
		$this->widgetService->expects($this->once())->method('deleteByPageIds')->with([self::GRID_PAGE]);
		$this->pageMapper->expects($this->once())->method('deleteByPageId')->with(self::GRID_PAGE);

		$this->assertSame('Overview', $this->service->delete(7, self::GRID_PAGE, 'alice')['title']);
	}

	public function testTheStartPageCannotBeDeleted(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$this->pageMapper->expects($this->never())->method('deleteByPageId');

		$this->expectException(BadRequestError::class);
		$this->service->delete(7, self::START_PAGE, 'alice');
	}

	public function testDeleteNeedsManagePermission(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(false);
		$this->pageMapper->expects($this->never())->method('deleteByPageId');

		$this->expectException(PermissionError::class);
		$this->service->delete(7, self::GRID_PAGE, 'bob');
	}
}
