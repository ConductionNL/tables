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
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PageServiceTest extends TestCase {
	private const USER = 'alice';

	private PageService $service;
	private ContextMapper&MockObject $contextMapper;
	private PageMapper&MockObject $pageMapper;
	private PageContentMapper&MockObject $pageContentMapper;
	private ContextService&MockObject $contextService;
	private PermissionsService&MockObject $permissionsService;
	private Context $context;
	/** @var PageContent[] */
	private array $inserted = [];

	protected function setUp(): void {
		parent::setUp();
		$this->contextMapper = $this->createMock(ContextMapper::class);
		$this->pageMapper = $this->createMock(PageMapper::class);
		$this->pageContentMapper = $this->createMock(PageContentMapper::class);
		$this->contextService = $this->createMock(ContextService::class);
		$this->permissionsService = $this->createMock(PermissionsService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->service = new PageService(
			$this->contextMapper,
			$this->pageMapper,
			$this->pageContentMapper,
			$this->contextService,
			$this->permissionsService,
			new GridWidgetTypes($l10n),
			$this->createMock(IDBConnection::class),
		);

		$this->context = new Context();
		$this->context->id = 7;
		$this->context->setNodes([
			20 => ['id' => 20, 'node_id' => 3, 'node_type' => Application::NODE_TYPE_TABLE, 'permissions' => 1],
		]);
		$this->context->setPages([
			1 => ['id' => 1, 'page_type' => Page::TYPE_STARTPAGE, 'title' => null, 'technical_name' => 'dashboard', 'content' => [
				11 => ['id' => 11, 'order' => 10, 'node_rel_id' => 20, 'type' => 'data', 'configuration' => null, 'grid_x' => null, 'grid_y' => null, 'grid_width' => null, 'grid_height' => null],
			]],
			2 => ['id' => 2, 'page_type' => Page::TYPE_GRID, 'title' => 'Sales', 'technical_name' => 'sales', 'content' => []],
		]);
		$this->contextMapper->method('findById')->willReturnCallback(function (int $contextId, ?string $userId): Context {
			if ($contextId !== 7 || $userId !== self::USER) {
				throw new NotFoundError('Context does not exist');
			}
			return $this->context;
		});
		$this->pageContentMapper->method('insert')->willReturnCallback(function (PageContent $content): PageContent {
			$this->inserted[] = $content;
			return $content;
		});
	}

	public function testFindReadsExistingContentAsDataWidgets(): void {
		$page = $this->service->find(7, 1, self::USER);

		$this->assertSame(Page::TYPE_STARTPAGE, $page['page_type']);
		$this->assertSame([[
			'id' => 11, 'order' => 10, 'node_rel_id' => 20, 'type' => 'data',
			'configuration' => ['title' => '', 'showTitle' => false], 'content' => [],
			'grid_x' => 0, 'grid_y' => 0, 'grid_width' => 12, 'grid_height' => 5,
		]], $page['content']);
	}

	public function testFindAllListsEveryPage(): void {
		$this->assertSame([1, 2], array_column($this->service->findAll(7, self::USER), 'id'));
	}

	public function testFindRejectsAPageOfAnotherApplication(): void {
		$this->expectException(NotFoundError::class);
		$this->service->find(7, 99, self::USER);
	}

	public function testCreateAddsAGridPage(): void {
		$this->permissionsService->method('canManageContextById')->with(7, self::USER)->willReturn(true);
		$this->pageMapper->expects($this->once())->method('insert')->willReturnCallback(function (Page $page): Page {
			$this->assertSame(Page::TYPE_GRID, $page->getPageType());
			$this->assertSame('Overview', $page->getTitle());
			$this->assertSame('overview', $page->getTechnicalName());
			$page->id = 3;
			$pages = $this->context->getPages();
			$pages[3] = ['id' => 3, 'page_type' => Page::TYPE_GRID, 'title' => 'Overview', 'technical_name' => 'overview', 'content' => []];
			$this->context->setPages($pages);
			return $page;
		});

		$page = $this->service->create(7, ' Overview ', 'overview', self::USER);

		$this->assertSame(3, $page['id']);
	}

	public static function invalidPageInput(): array {
		return [
			'empty title' => ['  ', null],
			'title too long' => [str_repeat('a', 201), null],
			'technical name with capitals' => ['Ok', 'Overview'],
			'technical name starting with a digit' => ['Ok', '1st'],
			'technical name taken' => ['Ok', 'sales'],
			'technical name of the start page' => ['Ok', 'dashboard'],
			'empty technical name' => ['Ok', ''],
			'technical name too long' => ['Ok', str_repeat('a', 201)],
		];
	}

	/**
	 * @dataProvider invalidPageInput
	 */
	public function testCreateRejectsInvalidInput(string $title, ?string $technicalName): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$this->pageMapper->expects($this->never())->method('insert');

		$this->expectException(BadRequestError::class);
		$this->service->create(7, $title, $technicalName, self::USER);
	}

	public function testNonManagerCannotWrite(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(false);
		$this->pageMapper->expects($this->never())->method('insert');
		$this->pageMapper->expects($this->never())->method('update');
		$this->pageMapper->expects($this->never())->method('deleteByPageId');
		$this->pageContentMapper->expects($this->never())->method('deleteByPageId');

		foreach ([
			fn () => $this->service->create(7, 'Overview', null, self::USER),
			fn () => $this->service->update(7, 2, 'Renamed', null, self::USER),
			fn () => $this->service->delete(7, 2, self::USER),
			fn () => $this->service->saveWidgets(7, 2, [], self::USER),
		] as $write) {
			try {
				$write();
				$this->fail('A write without manage permission went through');
			} catch (PermissionError) {
			}
		}
		$this->assertSame([], $this->inserted);
	}

	public function testUpdateRenamesAndKeepsItsOwnTechnicalName(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$stored = new Page();
		$stored->id = 2;
		$stored->setTechnicalName('sales');
		$this->pageMapper->method('findById')->with(2)->willReturn($stored);
		$this->pageMapper->expects($this->once())->method('update')->with($stored)->willReturnArgument(0);

		$this->service->update(7, 2, 'Revenue', 'sales', self::USER);

		$this->assertSame('Revenue', $stored->getTitle());
		$this->assertSame('sales', $stored->getTechnicalName());
	}

	public static function invalidTechnicalNameChange(): array {
		return [
			'empty' => [''],
			'invalid' => ['Sales report'],
			'taken by another page' => ['dashboard'],
		];
	}

	/**
	 * @dataProvider invalidTechnicalNameChange
	 */
	public function testUpdateRejectsAnInvalidTechnicalName(string $technicalName): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$stored = new Page();
		$stored->id = 2;
		$stored->setTechnicalName('sales');
		$this->pageMapper->method('findById')->willReturn($stored);
		$this->pageMapper->expects($this->never())->method('update');

		try {
			$this->service->update(7, 2, null, $technicalName, self::USER);
			$this->fail('The technical name was accepted');
		} catch (BadRequestError) {
		}
		$this->assertSame('sales', $stored->getTechnicalName());
	}

	public function testUpdateChangesTheTechnicalName(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$stored = new Page();
		$stored->id = 2;
		$stored->setTitle('Sales');
		$stored->setTechnicalName('sales');
		$this->pageMapper->method('findById')->willReturn($stored);
		$this->pageMapper->expects($this->once())->method('update')->with($stored)->willReturnArgument(0);

		$this->service->update(7, 2, null, 'revenue', self::USER);

		$this->assertSame('Sales', $stored->getTitle());
		$this->assertSame('revenue', $stored->getTechnicalName());
	}

	public function testUpdateLosingARaceForATechnicalNameIsABadRequest(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$stored = new Page();
		$stored->id = 2;
		$stored->setTechnicalName('sales');
		$this->pageMapper->method('findById')->willReturn($stored);
		$this->pageMapper->method('update')->willThrowException(new class('duplicate') extends DbException {
			public function getReason(): ?int {
				return self::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		});

		$this->expectException(BadRequestError::class);
		$this->service->update(7, 2, null, 'revenue', self::USER);
	}

	public static function generatedTechnicalNames(): array {
		return [
			'from the title' => ['Team overview', 'team_overview'],
			'made unique' => ['Sales', 'sales_2'],
			'start page name taken' => ['Dashboard', 'dashboard_2'],
			'title without letters' => ['2026', 'page_2026'],
		];
	}

	/**
	 * @dataProvider generatedTechnicalNames
	 */
	public function testCreateWithoutTechnicalNameMakesOneFromTheTitle(string $title, string $expected): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$inserted = null;
		$this->pageMapper->expects($this->once())->method('insert')->willReturnCallback(function (Page $page) use (&$inserted): Page {
			$inserted = $page;
			$page->id = 3;
			$pages = $this->context->getPages();
			$pages[3] = ['id' => 3, 'page_type' => Page::TYPE_GRID, 'title' => $page->getTitle(), 'technical_name' => $page->getTechnicalName(), 'content' => []];
			$this->context->setPages($pages);
			return $page;
		});

		$page = $this->service->create(7, $title, null, self::USER);

		$this->assertSame($expected, $inserted->getTechnicalName());
		$this->assertSame($expected, $page['technical_name']);
	}

	public function testStartPageCannotBeDeleted(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$this->pageMapper->expects($this->never())->method('deleteByPageId');

		$this->expectException(BadRequestError::class);
		$this->service->delete(7, 1, self::USER);
	}

	public function testDeleteRemovesAGridPageAndItsContent(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$this->pageContentMapper->expects($this->once())->method('deleteByPageId')->with(2);
		$this->pageMapper->expects($this->once())->method('deleteByPageId')->with(2);

		$this->assertSame(2, $this->service->delete(7, 2, self::USER)['id']);
	}

	public function testSaveWidgetsReplacesContentAndOrdersByPosition(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$this->permissionsService->expects($this->once())->method('canManageNodeById')
			->with(Application::NODE_TYPE_VIEW, 9, self::USER)->willReturn(true);
		$this->contextService->expects($this->once())->method('addNodeToContext')
			->with($this->context, 9, Application::NODE_TYPE_VIEW, Application::PERMISSION_READ)
			->willReturnCallback(static function (): ContextNodeRelation {
				$relation = new ContextNodeRelation();
				$relation->id = 21;
				return $relation;
			});
		$this->pageContentMapper->expects($this->once())->method('deleteByPageId')->with(1);

		$this->service->saveWidgets(7, 1, [
			['type' => 'data', 'node' => ['nodeType' => Application::NODE_TYPE_VIEW, 'nodeId' => 9], 'gridX' => 6, 'gridY' => 2, 'gridWidth' => 6, 'gridHeight' => 5],
			['type' => 'header', 'content' => ['title' => 'Welcome'], 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 12, 'gridHeight' => 2],
			['type' => 'data', 'nodeRelId' => 20, 'gridX' => 0, 'gridY' => 2, 'gridWidth' => 6, 'gridHeight' => 5],
			['type' => 'data', 'node' => ['nodeType' => Application::NODE_TYPE_TABLE, 'nodeId' => 3], 'gridX' => 0, 'gridY' => 7, 'gridWidth' => 12, 'gridHeight' => 5],
		], self::USER);

		$this->assertCount(4, $this->inserted);
		$this->assertSame([21, null, 20, 20], array_map(static fn (PageContent $content) => $content->getNodeRelId(), $this->inserted));
		$this->assertSame([30, 10, 20, 40], array_map(static fn (PageContent $content) => $content->getOrder(), $this->inserted));
		$this->assertSame(['data', 'header', 'data', 'data'], array_map(static fn (PageContent $content) => $content->getType(), $this->inserted));
		$this->assertSame([6, 2, 6, 5], [$this->inserted[0]->getGridX(), $this->inserted[0]->getGridY(), $this->inserted[0]->getGridWidth(), $this->inserted[0]->getGridHeight()]);
		$this->assertSame(
			['configuration' => ['title' => '', 'showTitle' => false], 'content' => ['title' => 'Welcome', 'subtitle' => '', 'textAlign' => 'left', 'backgroundColor' => '', 'textColor' => '']],
			json_decode($this->inserted[1]->getConfiguration(), true),
		);
	}

	public function testSaveWidgetsRefusesANodeTheUserCannotManage(): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$this->permissionsService->method('canManageNodeById')->willReturn(false);
		$this->contextService->expects($this->never())->method('addNodeToContext');
		$this->pageContentMapper->expects($this->never())->method('deleteByPageId');

		$this->expectException(PermissionError::class);
		$this->service->saveWidgets(7, 2, [
			['type' => 'data', 'node' => ['nodeType' => Application::NODE_TYPE_TABLE, 'nodeId' => 4], 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 12, 'gridHeight' => 5],
		], self::USER);
	}

	public static function invalidWidgets(): array {
		$position = ['gridX' => 0, 'gridY' => 0, 'gridWidth' => 12, 'gridHeight' => 5];
		return [
			'not an object' => ['header', 'Widget 0 must be an object'],
			'no type' => [$position, 'Widget 0 needs a type'],
			'unknown type' => [['type' => 'chart'] + $position, 'Unknown widget type'],
			'foreign node relation' => [['type' => 'data', 'nodeRelId' => 999] + $position, 'not part of this application'],
			'node relation as string' => [['type' => 'data', 'nodeRelId' => '20'] + $position, 'not part of this application'],
			'data without a node' => [['type' => 'data'] + $position, 'needs a nodeRelId'],
			'node of an unknown type' => [['type' => 'data', 'node' => ['nodeType' => 2, 'nodeId' => 1]] + $position, 'needs a nodeRelId'],
			'missing position' => [['type' => 'text'], 'needs gridX'],
			'negative position' => [['type' => 'text', 'gridX' => -1] + $position, 'needs gridX'],
			'zero height' => [['type' => 'text', 'gridHeight' => 0] + $position, 'needs gridHeight'],
			'wider than the grid' => [['type' => 'text', 'gridX' => 6] + $position, 'does not fit'],
			'invalid content' => [['type' => 'header', 'content' => ['title' => '']] + $position, 'header.title is required'],
		];
	}

	/**
	 * @dataProvider invalidWidgets
	 */
	public function testSaveWidgetsRejectsInvalidWidgets(mixed $widget, string $message): void {
		$this->permissionsService->method('canManageContextById')->willReturn(true);
		$this->pageContentMapper->expects($this->never())->method('deleteByPageId');

		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage($message);
		$this->service->saveWidgets(7, 2, [$widget], self::USER);
	}
}
