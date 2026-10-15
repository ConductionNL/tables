<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use InvalidArgumentException;
use OC\DB\Exceptions\DbalException;
use OCA\Tables\AppInfo\Application;
use OCA\Tables\Db\Context;
use OCA\Tables\Db\ContextMapper;
use OCA\Tables\Db\ContextNodeRelation;
use OCA\Tables\Db\ContextNodeRelationMapper;
use OCA\Tables\Db\MenuItem;
use OCA\Tables\Db\MenuItemMapper;
use OCA\Tables\Db\Page;
use OCA\Tables\Db\PageContent;
use OCA\Tables\Db\PageContentMapper;
use OCA\Tables\Db\PageMapper;
use OCA\Tables\Db\Table;
use OCA\Tables\Db\TableMapper;
use OCA\Tables\Db\View;
use OCA\Tables\Db\ViewMapper;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\Model\ContextScheme;
use OCA\Tables\Model\MenuItemInput;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IL10N;
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
	private MockObject $menuItemMapper;
	private MockObject $tableMapper;
	private MockObject $viewMapper;
	private MockObject $permissionsService;
	private array $constructorArgs;
	private MockObject $urlGenerator;

	protected function setUp(): void {
		parent::setUp();

		$this->contextMapper = $this->createMock(ContextMapper::class);
		$this->contextNodeRelMapper = $this->createMock(ContextNodeRelationMapper::class);
		$this->pageMapper = $this->createMock(PageMapper::class);
		$this->pageContentMapper = $this->createMock(PageContentMapper::class);
		$this->menuItemMapper = $this->createMock(MenuItemMapper::class);
		$this->tableMapper = $this->createMock(TableMapper::class);
		$this->viewMapper = $this->createMock(ViewMapper::class);
		$this->permissionsService = $this->createMock(PermissionsService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$userManager = $this->createMock(IUserManager::class);
		$eventDispatcher = $this->createMock(IEventDispatcher::class);
		$dbConnection = $this->createMock(IDBConnection::class);
		$shareService = $this->createMock(ShareService::class);
		$navigationManager = $this->createMock(INavigationManager::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'translated ' . $text);

		$this->constructorArgs = [
			$this->contextMapper,
			$this->contextNodeRelMapper,
			$this->pageMapper,
			$this->pageContentMapper,
			$logger,
			$this->permissionsService,
			$userManager,
			$eventDispatcher,
			$dbConnection,
			$shareService,
			false,
			$navigationManager,
			$this->urlGenerator,
			$this->tableMapper,
			$this->viewMapper,
			$this->menuItemMapper,
			$l10n,
		];
		$this->service = new ContextService(...$this->constructorArgs);
	}

	/**
	 * A service whose updates, scheme service and table services are mocked, to follow an import.
	 *
	 * @param list<string> $calls collects the steps of the import in order
	 */
	private function importingService(ContextSchemeService&MockObject $schemeService, array &$calls): ContextService&MockObject {
		$service = $this->getMockBuilder(ContextService::class)
			->setConstructorArgs($this->constructorArgs)
			->onlyMethods(['update', 'contextSchemeService', 'tableService', 'viewService', 'columnService'])
			->getMock();
		$service->method('contextSchemeService')->willReturn($schemeService);
		$service->method('tableService')->willReturn($this->createMock(TableService::class));
		$service->method('viewService')->willReturn($this->createMock(ViewService::class));
		$service->method('columnService')->willReturn($this->createMock(ColumnService::class));
		$service->method('update')->willReturnCallback(function (int $contextId, string $userId, ?string $name, ?string $icon, ?string $description, ?array $nodes, ?array $menuItems = null) use (&$calls): Context {
			$calls[] = $nodes !== null ? 'nodes:' . json_encode(array_column($nodes, 'id')) : 'menu:' . count($menuItems ?? []);
			return $this->contextWithMenu($contextId, []);
		});
		$this->contextMapper->method('findById')->willReturnCallback(fn (int $id): Context => $this->contextWithMenu($id, []));
		return $service;
	}

	private function scheme(array $nodes, ?array $pages, ?array $menuItems, array $gridViews = []): ContextScheme {
		return new ContextScheme('Intake', 'star', '', $nodes, $pages, ['addTables' => [], 'modifyTables' => []], $menuItems, $gridViews);
	}

	public function testUpdateRejectsAMalformedTechnicalName(): void {
		$this->contextMapper->method('findById')->willReturn($this->contextWithMenu(7, []));

		$this->expectException(InvalidArgumentException::class);
		$this->service->update(7, 'user-1', null, null, null, null, null, 'Not A Name');
	}

	public function testUpdateRejectsATechnicalNameTheOwnerAlreadyUses(): void {
		$this->contextMapper->method('findById')->willReturn($this->contextWithMenu(7, []));
		$this->contextMapper->method('isTechnicalNameTaken')->with('intake', 'user-1', 7)->willReturn(true);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('already exists');
		$this->service->update(7, 'user-1', null, null, null, null, null, 'intake');
	}

	public function testUpdateClearsTheTechnicalNameWhenItIsBlank(): void {
		$context = $this->contextWithMenu(7, []);
		$context->setTechnicalName('old');
		$this->contextMapper->method('findById')->willReturn($context);
		$this->contextMapper->method('update')->willReturnArgument(0);

		$updated = $this->service->update(7, 'user-1', null, null, null, null, null, '  ');

		$this->assertNull($updated->getTechnicalName());
	}

	public function testUpdateLeavesTheTechnicalNameAloneWhenNoneIsGiven(): void {
		$context = $this->contextWithMenu(7, []);
		$context->setTechnicalName('intake');
		$this->contextMapper->method('findById')->willReturn($context);
		$this->contextMapper->method('update')->willReturnArgument(0);
		$this->contextMapper->expects($this->never())->method('isTechnicalNameTaken');

		$updated = $this->service->update(7, 'user-1', 'Renamed', null, null, null);

		$this->assertSame('intake', $updated->getTechnicalName());
	}

	public function testCreateStoresTheTechnicalName(): void {
		$this->contextMapper->method('insert')->willReturnCallback(static function (Context $context): Context {
			$context->setId(8);
			return $context;
		});
		$this->pageMapper->method('insert')->willReturnCallback(static function (Page $page): Page {
			$page->setId(3);
			return $page;
		});
		$this->menuItemMapper->method('findByContextId')->willReturn([]);

		$created = $this->service->create('Intake portal', 'heart', '', [], 'user-1', 0, [], 'intake');

		$this->assertSame('intake', $created->getTechnicalName());
	}

	public function testNavigationLinksOwnApplicationsByTechnicalNameAndOthersById(): void {
		$own = $this->contextWithMenu(7, []);
		$own->setTechnicalName('intake');
		$shared = $this->contextWithMenu(8, []);
		$shared->setOwnerId('someone-else');
		$shared->setTechnicalName('intake');
		$this->contextMapper->method('findForNavBar')->willReturn([$own, $shared]);
		$linked = [];
		$this->urlGenerator->method('linkToRoute')->willReturnCallback(static function (string $route, array $parameters) use (&$linked): string {
			$linked[] = $parameters['contextId'];
			return '/app/' . $parameters['contextId'];
		});

		$this->service->addToNavigation('user-1');

		$this->assertSame(['intake', 8], $linked);
	}

	public function testImportGivesTheApplicationTheTechnicalNameOfTheScheme(): void {
		$names = [];
		$service = $this->getMockBuilder(ContextService::class)
			->setConstructorArgs($this->constructorArgs)
			->onlyMethods(['update', 'contextSchemeService', 'tableService', 'viewService', 'columnService'])
			->getMock();
		$service->method('contextSchemeService')->willReturn($this->createMock(ContextSchemeService::class));
		$service->method('update')->willReturnCallback(function (int $contextId, string $userId, ?string $name, ?string $icon, ?string $description, ?array $nodes, ?array $menuItems = null, ?string $technicalName = null) use (&$names): Context {
			$names[] = $technicalName;
			return $this->contextWithMenu($contextId, []);
		});
		$this->contextMapper->method('findById')->willReturn($this->contextWithMenu(7, []));

		$service->importScheme(7, new ContextScheme('Intake', 'star', '', [], null, ['addTables' => [], 'modifyTables' => []], null, [], 'intake'), 'user-1');

		$this->assertSame(['intake'], $names);
	}

	public function testImportSetsTheNodesThenThePagesThenTheMenu(): void {
		$calls = [];
		$schemeService = $this->createMock(ContextSchemeService::class);
		$schemeService->expects($this->once())->method('importPages')->willReturnCallback(static function () use (&$calls): array {
			$calls[] = 'pages';
			return ['pages' => [3 => 30], 'gridViews' => ['grid-1' => 31]];
		});
		$schemeService->expects($this->once())->method('resolveMenuItems')
			->with([['label' => 'Home']], 'user-1', [3 => 30], ['grid-1' => 31])
			->willReturn([MenuItemInput::createFromInputArray(['label' => 'Home', 'targetType' => 'page', 'targetId' => 30])]);
		$table = new Table();
		$table->setId(5);
		$this->tableMapper->method('findByUuid')->with('table-uuid')->willReturn($table);
		$service = $this->importingService($schemeService, $calls);

		$service->importScheme(7, $this->scheme(
			[
				['id' => 70, 'node_type' => Application::NODE_TYPE_TABLE, 'node_uuid' => 'table-uuid', 'permissions' => 1],
				['id' => 71, 'node_type' => Application::NODE_TYPE_VIEW, 'node_uuid' => 'grid-1', 'permissions' => 1],
			],
			[['id' => 3, 'page_type' => 'startpage', 'content' => []]],
			[['label' => 'Home']],
			[['uuid' => 'grid-1', 'title' => 'Old page']],
		), 'user-1');

		// The grid view node is no node any more: it became a page
		$this->assertSame(['nodes:[5]', 'pages', 'menu:1'], $calls);
	}

	public function testImportOfASchemeWithoutPagesOrMenuLeavesBothAlone(): void {
		$calls = [];
		$schemeService = $this->createMock(ContextSchemeService::class);
		$schemeService->expects($this->never())->method('importPages');
		$schemeService->expects($this->never())->method('resolveMenuItems');
		$service = $this->importingService($schemeService, $calls);

		$service->importScheme(7, $this->scheme([], null, null), 'user-1');

		$this->assertSame(['nodes:[]'], $calls);
	}

	public function testImportOfAnEmptyMenuClearsTheMenu(): void {
		$calls = [];
		$schemeService = $this->createMock(ContextSchemeService::class);
		$schemeService->method('resolveMenuItems')->with([])->willReturn([]);
		$service = $this->importingService($schemeService, $calls);

		$service->importScheme(7, $this->scheme([], null, []), 'user-1');

		$this->assertSame(['nodes:[]', 'menu:0'], $calls);
	}

	public function testExportCarriesThePagesAndTheMenu(): void {
		$context = $this->contextWithMenu(7, []);
		$this->contextMapper->method('findById')->willReturn($context);
		$schemeService = $this->createMock(ContextSchemeService::class);
		$schemeService->method('exportPages')->with(7, 'user-1')->willReturn([['id' => 3, 'page_type' => 'startpage', 'content' => []]]);
		$schemeService->method('exportMenuItems')->with($context)->willReturn([['label' => 'Home', 'targetType' => 'page', 'targetPageId' => 3]]);
		$service = $this->getMockBuilder(ContextService::class)
			->setConstructorArgs($this->constructorArgs)
			->onlyMethods(['contextSchemeService', 'tableService', 'viewService'])
			->getMock();
		$service->method('contextSchemeService')->willReturn($schemeService);
		$service->method('tableService')->willReturn($this->createMock(TableService::class));
		$service->method('viewService')->willReturn($this->createMock(ViewService::class));

		$scheme = $service->getScheme(7, 'user-1')->jsonSerialize();

		$this->assertSame([['id' => 3, 'page_type' => 'startpage', 'content' => []]], $scheme['pages']);
		$this->assertSame([['label' => 'Home', 'targetType' => 'page', 'targetPageId' => 3]], $scheme['menuItems']);
	}

	private function contextWithMenu(int $id, array $menuItems): Context {
		$context = new Context();
		$context->id = $id;
		$context->setName('Intake portal');
		$context->setOwnerId('user-1');
		$context->setOwnerType(Application::OWNER_TYPE_USER);
		$context->setNodes([]);
		$context->setPages([]);
		$context->setMenuItems($menuItems);
		return $context;
	}

	/**
	 * @param list<MenuItem> $inserted collects the stored items
	 */
	private function expectMenuReplacement(int $contextId, array &$inserted): void {
		$this->menuItemMapper->expects($this->once())->method('deleteAllByContextId')->with($contextId);
		$this->menuItemMapper->method('insert')->willReturnCallback(static function (MenuItem $item) use (&$inserted): MenuItem {
			$item->setId(count($inserted) + 1);
			$inserted[] = $item;
			return $item;
		});
		$this->menuItemMapper->method('findByContextId')->willReturnCallback(static function () use (&$inserted): array {
			return $inserted;
		});
	}

	private function expectExistingTargets(array $views, array $tables): void {
		$this->viewMapper->method('findMany')->willReturnCallback(static fn (array $ids): array => array_intersect_key($views, array_flip($ids)));
		$this->tableMapper->method('findMany')->willReturnCallback(static fn (array $ids): array => array_intersect_key($tables, array_flip($ids)));
	}

	private function expectNodeInsertion(array &$relations): void {
		$this->contextNodeRelMapper->method('insert')->willReturnCallback(static function (ContextNodeRelation $relation) use (&$relations): ContextNodeRelation {
			$relation->setId(200 + count($relations));
			$relations[] = $relation;
			return $relation;
		});
	}

	public function testUpdateReplacesTheMenuInOrderAndDerivesTechnicalNamesFromLabels(): void {
		$context = $this->contextWithMenu(7, []);
		$this->contextMapper->method('findById')->with(7, 'user-1')->willReturn($context);
		$this->contextMapper->method('update')->willReturnArgument(0);
		$this->permissionsService->method('canManageNodeById')->willReturn(true);
		$this->expectExistingTargets([6 => new View()], [4 => new Table()]);
		$relations = [];
		$this->expectNodeInsertion($relations);
		$inserted = [];
		$this->expectMenuReplacement(7, $inserted);

		$updated = $this->service->update(7, 'user-1', null, null, null, null, MenuItemInput::createListFromInputArray([
			['label' => 'Intake home', 'targetType' => 'view', 'targetId' => 6, 'technicalName' => 'home'],
			['label' => 'Welcome table!', 'targetType' => 'table', 'targetId' => 4],
			['label' => 'Docs', 'targetType' => 'url', 'url' => 'https://docs.nextcloud.com'],
		]));

		$this->assertSame([10, 20, 30], array_map(static fn (MenuItem $item) => $item->getOrder(), $inserted));
		$this->assertSame(['home', 'welcome_table', 'docs'], array_map(static fn (MenuItem $item) => $item->getTechnicalName(), $inserted));
		$this->assertSame([6, 4, null], array_map(static fn (MenuItem $item) => $item->getTargetId(), $inserted));
		$this->assertSame([null, null, 'https://docs.nextcloud.com'], array_map(static fn (MenuItem $item) => $item->getUrl(), $inserted));
		$this->assertCount(3, $updated->getMenuItems());
		$this->assertSame('Welcome table!', $updated->getMenuItems()[1]['label']);
	}

	public function testUpdateStoresAChildUnderItsParentAndKeepsTheOrder(): void {
		$this->contextMapper->method('findById')->willReturn($this->contextWithMenu(7, []));
		$this->contextMapper->method('update')->willReturnArgument(0);
		$this->expectExistingTargets([6 => new View()], [4 => new Table()]);
		$this->permissionsService->method('canManageNodeById')->willReturn(true);
		$relations = [];
		$this->expectNodeInsertion($relations);
		$inserted = [];
		$this->expectMenuReplacement(7, $inserted);

		$updated = $this->service->update(7, 'user-1', null, null, null, null, MenuItemInput::createListFromInputArray([
			['label' => 'Cases', 'targetType' => 'table', 'targetId' => 4],
			['label' => 'Open cases', 'targetType' => 'view', 'targetId' => 6, 'parentIndex' => 0],
			['label' => 'Docs', 'targetType' => 'url', 'url' => 'https://example.org'],
		]));

		$byLabel = [];
		foreach ($inserted as $item) {
			$byLabel[$item->getLabel()] = $item;
		}
		$this->assertNull($byLabel['Cases']->getParentId());
		$this->assertSame($byLabel['Cases']->getId(), $byLabel['Open cases']->getParentId());
		$this->assertSame([10, 20, 30], [$byLabel['Cases']->getOrder(), $byLabel['Open cases']->getOrder(), $byLabel['Docs']->getOrder()]);
		$this->assertCount(3, $updated->getMenuItems());
	}

	public function testReplaceMenuRefusesAChildUnderAChild(): void {
		$this->contextMapper->method('findById')->willReturn($this->contextWithMenu(7, []));
		$this->contextMapper->method('update')->willReturnArgument(0);
		$inserted = [];
		$this->expectMenuReplacement(7, $inserted);

		// built without createListFromInputArray, which already refuses this, to reach the service's own guard
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('top-level');
		$this->service->update(7, 'user-1', null, null, null, null, [
			new MenuItemInput('A', null, 'url', null, 'https://a.example', null),
			new MenuItemInput('B', null, 'url', null, 'https://b.example', null, 0),
			new MenuItemInput('C', null, 'url', null, 'https://c.example', null, 1),
		]);
	}

	public function testMenuTargetsBecomeNodesSoRecipientsCanOpenThem(): void {
		$context = $this->contextWithMenu(7, []);
		$context->setNodes([101 => ['id' => 101, 'node_id' => 4, 'node_type' => Application::NODE_TYPE_TABLE, 'permissions' => 1]]);
		$context->setPages([301 => ['id' => 301, 'page_type' => Page::TYPE_STARTPAGE, 'content' => []]]);
		$this->contextMapper->method('findById')->willReturn($context);
		$this->contextMapper->method('update')->willReturnArgument(0);
		$this->permissionsService->method('canManageNodeById')->willReturn(true);
		$this->expectExistingTargets([6 => new View()], [4 => new Table()]);
		$relations = [];
		$this->expectNodeInsertion($relations);
		$this->pageContentMapper->method('findByPageAndNodeRelation')->willReturn(null);
		$this->pageContentMapper->method('insert')->willReturnCallback(static function (PageContent $content): PageContent {
			$content->setId(501);
			return $content;
		});
		$inserted = [];
		$this->expectMenuReplacement(7, $inserted);

		$updated = $this->service->update(7, 'user-1', null, null, null, null, MenuItemInput::createListFromInputArray([
			['label' => 'Home', 'targetType' => 'view', 'targetId' => 6],
			['label' => 'Requests', 'targetType' => 'table', 'targetId' => 4],
		]));

		// the table already is a node, only the view is added, with read permission
		$this->assertCount(1, $relations);
		$this->assertSame([6, Application::NODE_TYPE_VIEW, Application::PERMISSION_READ], [$relations[0]->getNodeId(), $relations[0]->getNodeType(), $relations[0]->getPermissions()]);
		$this->assertSame(['id' => 200, 'node_id' => 6, 'node_type' => Application::NODE_TYPE_VIEW, 'permissions' => Application::PERMISSION_READ], $updated->getNodes()[200]);
		$this->assertSame(200, $updated->getPages()[301]['content'][501]['node_rel_id']);
	}

	public static function unusableNewTargets(): array {
		return [
			'exists but cannot be managed' => [[4 => new Table()]],
			'does not exist' => [[]],
		];
	}

	/**
	 * Both get the same answer, so a save reveals nothing about which ids exist.
	 *
	 * @dataProvider unusableNewTargets
	 */
	public function testANewMenuTargetThatCannotBeUsedIsRefusedTheSameWay(array $tables): void {
		$this->contextMapper->method('findById')->willReturn($this->contextWithMenu(7, []));
		$this->contextMapper->method('update')->willReturnArgument(0);
		$this->expectExistingTargets([], $tables);
		$this->permissionsService->method('canManageNodeById')->willReturn(false);
		$this->menuItemMapper->expects($this->never())->method('deleteAllByContextId');

		$this->expectException(PermissionError::class);
		$this->expectExceptionMessage('Owner cannot manage node 4 (type 0)');
		$this->service->update(7, 'user-1', null, null, null, null, MenuItemInput::createListFromInputArray([
			['label' => 'Requests', 'targetType' => 'table', 'targetId' => 4],
		]));
	}

	public function testAStoredMenuTargetThatNoLongerExistsIsKeptWithoutANode(): void {
		$this->contextMapper->method('findById')->willReturn($this->contextWithMenu(7, [
			['targetType' => 'view', 'targetId' => 99, 'url' => null, 'technicalName' => 'gone'],
		]));
		$this->contextMapper->method('update')->willReturnArgument(0);
		$this->expectExistingTargets([], []);
		$this->contextNodeRelMapper->expects($this->never())->method('insert');
		$inserted = [];
		$this->expectMenuReplacement(7, $inserted);

		$this->service->update(7, 'user-1', null, null, null, null, MenuItemInput::createListFromInputArray([
			['label' => 'Gone', 'targetType' => 'view', 'targetId' => 99],
		]));

		$this->assertSame([99], array_map(static fn (MenuItem $item) => $item->getTargetId(), $inserted));
	}

	public function testAnItemSentWithoutANameKeepsTheStoredNameOfItsTarget(): void {
		$context = $this->contextWithMenu(7, [
			['targetType' => 'view', 'targetId' => 6, 'url' => null, 'technicalName' => 'home'],
			['targetType' => 'url', 'targetId' => null, 'url' => 'https://example.org/a', 'technicalName' => 'docs'],
			['targetType' => 'url', 'targetId' => null, 'url' => 'https://example.org/b', 'technicalName' => 'docs_2'],
		]);
		$context->setNodes([101 => ['id' => 101, 'node_id' => 6, 'node_type' => Application::NODE_TYPE_VIEW, 'permissions' => 1]]);
		$this->contextMapper->method('findById')->willReturn($context);
		$this->contextMapper->method('update')->willReturnArgument(0);
		$inserted = [];
		$this->expectMenuReplacement(7, $inserted);

		// the view is renamed, the two links swap places and a new link is added
		$this->service->update(7, 'user-1', null, null, null, null, MenuItemInput::createListFromInputArray([
			['label' => 'Start', 'targetType' => 'view', 'targetId' => 6],
			['label' => 'Docs', 'url' => 'https://example.org/b'],
			['label' => 'Docs', 'url' => 'https://example.org/a'],
			['label' => 'Docs', 'url' => 'https://example.org/c'],
		]));

		$this->assertSame(['home', 'docs_2', 'docs', 'docs_3'], array_map(static fn (MenuItem $item) => $item->getTechnicalName(), $inserted));
	}

	public function testDerivedTechnicalNamesGetASuffixWhenTheMenuAlreadyUsesThem(): void {
		$this->contextMapper->method('findById')->willReturn($this->contextWithMenu(7, []));
		$this->contextMapper->method('update')->willReturnArgument(0);
		$inserted = [];
		$this->expectMenuReplacement(7, $inserted);

		$this->service->update(7, 'user-1', null, null, null, null, MenuItemInput::createListFromInputArray([
			['label' => 'Docs', 'url' => 'https://example.org/a'],
			['label' => 'Docs', 'url' => 'https://example.org/b'],
			['label' => 'Other', 'url' => 'https://example.org/c', 'technicalName' => 'docs_2'],
		]));

		$this->assertSame(['docs', 'docs_3', 'docs_2'], array_map(static fn (MenuItem $item) => $item->getTechnicalName(), $inserted));
	}

	public function testTwoMenuItemsWithTheSameTechnicalNameAreRefused(): void {
		$this->contextMapper->method('findById')->willReturn($this->contextWithMenu(7, []));
		$this->contextMapper->method('update')->willReturnArgument(0);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('share the technical name "home"');
		$this->service->update(7, 'user-1', null, null, null, null, MenuItemInput::createListFromInputArray([
			['label' => 'A', 'url' => 'https://example.org/a', 'technicalName' => 'home'],
			['label' => 'B', 'url' => 'https://example.org/b', 'technicalName' => 'home'],
		]));
	}

	public function testUpdateRunsAgainWhenTheDatabaseAsksForARetry(): void {
		$locked = $this->createMock(DbalException::class);
		$locked->method('isRetryable')->willReturn(true);
		$this->contextMapper->expects($this->exactly(2))->method('findById')
			->willReturnOnConsecutiveCalls($this->throwException($locked), $this->contextWithMenu(7, []));
		$this->contextMapper->method('update')->willReturnArgument(0);

		$updated = $this->service->update(7, 'user-1', 'Renamed', null, null, null);

		$this->assertSame('Renamed', $updated->getName());
	}

	public function testUpdateLeavesTheMenuAloneWhenNoneIsGiven(): void {
		$context = $this->contextWithMenu(7, [['id' => 1, 'label' => 'Keep me']]);
		$this->contextMapper->method('findById')->willReturn($context);
		$this->contextMapper->method('update')->willReturnArgument(0);
		$this->menuItemMapper->expects($this->never())->method('deleteAllByContextId');

		$updated = $this->service->update(7, 'user-1', 'Renamed', null, null, null);

		$this->assertSame('Renamed', $updated->getName());
		$this->assertSame('Keep me', $updated->getMenuItems()[0]['label']);
	}

	public function testCreateStoresTheMenuItems(): void {
		$this->contextMapper->method('insert')->willReturnCallback(static function (Context $context): Context {
			$context->setId(8);
			return $context;
		});
		$this->pageMapper->method('insert')->willReturnCallback(static function (Page $page): Page {
			$page->setId(3);
			return $page;
		});
		$inserted = [];
		$this->expectMenuReplacement(8, $inserted);

		$created = $this->service->create('Intake portal', 'heart', ' desc ', [], 'user-1', 0, MenuItemInput::createListFromInputArray([
			['label' => 'Docs', 'targetType' => 'url', 'url' => 'https://example.org'],
		], allowNewStartPage: true));

		$this->assertSame('desc', $created->getDescription());
		$this->assertCount(2, $created->getMenuItems());
		$this->assertSame(['dashboard', 'docs'], array_map(static fn (MenuItem $item) => $item->getTechnicalName(), $inserted));
	}

	/**
	 * @param list<MenuItem> $inserted collects the stored items
	 */
	private function expectCreation(int $contextId, int $startPageId, array &$inserted): void {
		$this->contextMapper->method('insert')->willReturnCallback(static function (Context $context) use ($contextId): Context {
			$context->setId($contextId);
			return $context;
		});
		$this->pageMapper->method('insert')->willReturnCallback(static function (Page $page) use ($startPageId): Page {
			$page->setId($startPageId);
			return $page;
		});
		$this->expectMenuReplacement($contextId, $inserted);
	}

	/**
	 * @param list<MenuItem> $items
	 * @return list<array{string, string, ?int, string, ?string}>
	 */
	private static function describeMenu(array $items): array {
		return array_map(static fn (MenuItem $item): array => [
			$item->getLabel(), $item->getTargetType(), $item->getTargetId(), $item->getTechnicalName(), $item->getIcon(),
		], $items);
	}

	public function testCreateWithoutAMenuAddsADashboardItemForTheStartPage(): void {
		$inserted = [];
		$this->expectCreation(8, 3, $inserted);

		$created = $this->service->create('Intake portal', 'heart', '', [], 'user-1', 0);

		$this->assertSame([['translated Dashboard', 'page', 3, 'dashboard', null]], self::describeMenu($inserted));
		$this->assertSame(3, $created->getMenuItems()[0]['targetId']);
	}

	public function testCreatePutsTheDashboardItemFirstWithAFreeTechnicalName(): void {
		$inserted = [];
		$this->expectCreation(8, 3, $inserted);

		$this->service->create('Intake portal', 'heart', '', [], 'user-1', 0, MenuItemInput::createListFromInputArray([
			['label' => 'Docs', 'url' => 'https://example.org', 'technicalName' => 'dashboard'],
		], allowNewStartPage: true));

		$this->assertSame([
			['translated Dashboard', 'page', 3, 'dashboard_2', null],
			['Docs', 'url', null, 'dashboard', null],
		], self::describeMenu($inserted));
	}

	public function testCreateResolvesAPageItemWithoutAnIdToTheStartPage(): void {
		$inserted = [];
		$this->expectCreation(8, 3, $inserted);

		$this->service->create('Intake portal', 'heart', '', [], 'user-1', 0, MenuItemInput::createListFromInputArray([
			['label' => 'Docs', 'url' => 'https://example.org'],
			['label' => 'Overview', 'targetType' => 'page', 'icon' => 'home'],
		], allowNewStartPage: true));

		$this->assertSame([
			['Docs', 'url', null, 'docs', null],
			['Overview', 'page', 3, 'overview', 'home'],
		], self::describeMenu($inserted));
	}

	/**
	 * @param list<MenuItem> $items
	 * @return array<string, ?string> the label of each item's parent, by label
	 */
	private static function parentLabels(array $items): array {
		$labels = [];
		foreach ($items as $item) {
			$labels[$item->getId()] = $item->getLabel();
		}
		$parents = [];
		foreach ($items as $item) {
			$parents[$item->getLabel()] = $item->getParentId() === null ? null : $labels[$item->getParentId()];
		}
		return $parents;
	}

	public function testCreateKeepsChildrenUnderTheirParentWhenTheDashboardItemGoesFirst(): void {
		$inserted = [];
		$this->expectCreation(8, 3, $inserted);

		$this->service->create('Intake portal', 'heart', '', [], 'user-1', 0, MenuItemInput::createListFromInputArray([
			['label' => 'Docs', 'url' => 'https://example.org/docs'],
			['label' => 'Guide', 'url' => 'https://example.org/guide', 'parentIndex' => 0],
		], allowNewStartPage: true));

		$this->assertSame(['translated Dashboard' => null, 'Docs' => null, 'Guide' => 'Docs'], self::parentLabels($inserted));
	}

	public function testCreateStoresChildrenUnderTheStartPageItem(): void {
		$inserted = [];
		$this->expectCreation(8, 3, $inserted);

		$this->service->create('Intake portal', 'heart', '', [], 'user-1', 0, MenuItemInput::createListFromInputArray([
			['label' => 'Overview', 'targetType' => 'page'],
			['label' => 'Docs', 'url' => 'https://example.org/docs', 'parentIndex' => 0],
		], allowNewStartPage: true));

		$this->assertSame(['Overview' => null, 'Docs' => 'Overview'], self::parentLabels($inserted));
		$this->assertSame([3, null], array_map(static fn (MenuItem $item) => $item->getTargetId(), $inserted));
	}

	public function testAPageOfTheApplicationIsAcceptedAndAddsNoNode(): void {
		$context = $this->contextWithMenu(7, []);
		$context->setPages([301 => ['id' => 301, 'page_type' => Page::TYPE_STARTPAGE, 'content' => []]]);
		$this->contextMapper->method('findById')->willReturn($context);
		$this->contextMapper->method('update')->willReturnArgument(0);
		$this->contextNodeRelMapper->expects($this->never())->method('insert');
		$this->pageContentMapper->expects($this->never())->method('insert');
		$inserted = [];
		$this->expectMenuReplacement(7, $inserted);

		$updated = $this->service->update(7, 'user-1', null, null, null, null, MenuItemInput::createListFromInputArray([
			['label' => 'Dashboard', 'targetType' => 'page', 'targetId' => 301],
		]));

		$this->assertSame([['Dashboard', 'page', 301, 'dashboard', null]], self::describeMenu($inserted));
		$this->assertSame([], $updated->getNodes());
	}

	public function testAPageOfAnotherApplicationIsRefused(): void {
		$context = $this->contextWithMenu(7, []);
		$context->setPages([301 => ['id' => 301, 'page_type' => Page::TYPE_STARTPAGE, 'content' => []]]);
		$this->contextMapper->method('findById')->willReturn($context);
		$this->contextMapper->method('update')->willReturnArgument(0);
		$this->menuItemMapper->expects($this->never())->method('deleteAllByContextId');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('a page of its own application');
		$this->service->update(7, 'user-1', null, null, null, null, MenuItemInput::createListFromInputArray([
			['label' => 'Elsewhere', 'targetType' => 'page', 'targetId' => 302],
		]));
	}

	public function testAStoredPageItemWhosePageIsGoneIsKept(): void {
		$context = $this->contextWithMenu(7, [
			['targetType' => 'page', 'targetId' => 302, 'url' => null, 'technicalName' => 'overview', 'broken' => true],
		]);
		$context->setPages([301 => ['id' => 301, 'page_type' => Page::TYPE_STARTPAGE, 'content' => []]]);
		$this->contextMapper->method('findById')->willReturn($context);
		$this->contextMapper->method('update')->willReturnArgument(0);
		$this->contextNodeRelMapper->expects($this->never())->method('insert');
		$inserted = [];
		$this->expectMenuReplacement(7, $inserted);

		$this->service->update(7, 'user-1', null, null, null, null, MenuItemInput::createListFromInputArray([
			['label' => 'Overview', 'targetType' => 'page', 'targetId' => 302],
			['label' => 'Docs', 'url' => 'https://example.org'],
		]));

		$this->assertSame([['Overview', 'page', 302, 'overview', null], ['Docs', 'url', null, 'docs', null]], self::describeMenu($inserted));
	}

	public static function newPageTargetsOutsideTheApplication(): array {
		return [
			'page of another application' => [302],
			'page id that does not exist' => [9999],
		];
	}

	/**
	 * @dataProvider newPageTargetsOutsideTheApplication
	 */
	public function testANewPageTargetOutsideTheApplicationIsRefusedEvenWithABrokenStoredOne(int $pageId): void {
		// the stored item points at a page that is gone; that does not let other pages in
		$context = $this->contextWithMenu(7, [
			['targetType' => 'page', 'targetId' => 303, 'url' => null, 'technicalName' => 'gone', 'broken' => true],
		]);
		$context->setPages([301 => ['id' => 301, 'page_type' => Page::TYPE_STARTPAGE, 'content' => []]]);
		$this->contextMapper->method('findById')->willReturn($context);
		$this->contextMapper->method('update')->willReturnArgument(0);
		$this->menuItemMapper->expects($this->never())->method('deleteAllByContextId');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('a page of its own application');
		$this->service->update(7, 'user-1', null, null, null, null, MenuItemInput::createListFromInputArray([
			['label' => 'Gone', 'targetType' => 'page', 'targetId' => 303],
			['label' => 'Elsewhere', 'targetType' => 'page', 'targetId' => $pageId],
		]));
	}

	public function testUpdateRefusesAPageItemWithoutAnId(): void {
		$context = $this->contextWithMenu(7, []);
		$context->setPages([301 => ['id' => 301, 'page_type' => Page::TYPE_STARTPAGE, 'content' => []]]);
		$this->contextMapper->method('findById')->willReturn($context);
		$this->contextMapper->method('update')->willReturnArgument(0);
		$this->menuItemMapper->expects($this->never())->method('deleteAllByContextId');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('a page of its own application');
		$this->service->update(7, 'user-1', null, null, null, null, MenuItemInput::createListFromInputArray([
			['label' => 'Dashboard', 'targetType' => 'page'],
		], allowNewStartPage: true));
	}

	public function testUpdateAddsNoDashboardItem(): void {
		$context = $this->contextWithMenu(7, []);
		$context->setPages([301 => ['id' => 301, 'page_type' => Page::TYPE_STARTPAGE, 'content' => []]]);
		$this->contextMapper->method('findById')->willReturn($context);
		$this->contextMapper->method('update')->willReturnArgument(0);
		$inserted = [];
		$this->expectMenuReplacement(7, $inserted);

		$this->service->update(7, 'user-1', null, null, null, null, MenuItemInput::createListFromInputArray([
			['label' => 'Docs', 'url' => 'https://example.org'],
		]));

		$this->assertSame([['Docs', 'url', null, 'docs', null]], self::describeMenu($inserted));
	}

	public function testDeleteRemovesTheMenuOfTheApplication(): void {
		$context = new Context();
		$context->id = 7;
		$this->contextMapper->method('findById')->with(7, 'alice')->willReturn($context);
		$this->pageMapper->method('getPageIdsForContext')->willReturn([]);

		$this->menuItemMapper->expects($this->once())->method('deleteAllByContextId')->with(7);

		$this->service->delete(7, 'alice');
	}

	/**
	 * On SQLite a transaction that reads before it writes fails at once when another request
	 * writes meanwhile, so the delete takes the write lock first.
	 */
	public function testDeleteRemovesTheApplicationBeforeWhatBelongsToIt(): void {
		$context = new Context();
		$context->id = 7;
		$this->contextMapper->method('findById')->willReturn($context);
		$this->pageMapper->method('getPageIdsForContext')->willReturn([]);
		$calls = [];
		$this->contextMapper->method('delete')->willReturnCallback(static function (Context $deleted) use (&$calls): Context {
			$calls[] = 'application';
			return $deleted;
		});
		$this->menuItemMapper->method('deleteAllByContextId')->willReturnCallback(static function () use (&$calls): void {
			$calls[] = 'menu';
		});

		$this->service->delete(7, 'alice');

		$this->assertSame(['application', 'menu'], $calls);
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
		self::assertNull($insertedPages[0]->getTechnicalName());

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

	public function testImportContextDropsInvalidOrDuplicateTechnicalNames(): void {
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

		self::assertSame([null, 'sales', null], array_map(static fn (Page $page): ?string => $page->getTechnicalName(), $insertedPages));
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

	public function testUpdateKeepsTheOrderOfAStartPageArrangedOnTheGrid(): void {
		$context = new Context();
		$context->id = 7;
		$context->setNodes([
			101 => ['id' => 101, 'node_type' => 0, 'node_id' => 11, 'permissions' => 1],
			102 => ['id' => 102, 'node_type' => 0, 'node_id' => 12, 'permissions' => 1],
		]);
		$context->setPages([
			301 => ['id' => 301, 'page_type' => Page::TYPE_STARTPAGE, 'content' => [
				500 => ['id' => 500, 'node_rel_id' => null, 'type' => 'header', 'order' => 10, 'grid_x' => 0, 'grid_y' => 0, 'grid_width' => 12, 'grid_height' => 2],
				501 => ['id' => 501, 'node_rel_id' => 101, 'type' => 'data', 'order' => 20, 'grid_x' => 0, 'grid_y' => 2, 'grid_width' => 6, 'grid_height' => 5],
				502 => ['id' => 502, 'node_rel_id' => 102, 'type' => 'data', 'order' => 30, 'grid_x' => 6, 'grid_y' => 2, 'grid_width' => 6, 'grid_height' => 5],
			]],
		]);
		$this->contextMapper->method('findById')->willReturn($context);
		$this->contextMapper->method('update')->willReturnArgument(0);
		$this->pageContentMapper->expects($this->never())->method('update');

		$updated = $this->service->update(7, 'user-1', 'Renamed', null, null, [
			['id' => 11, 'type' => 0, 'permissions' => 1],
			['id' => 12, 'type' => 0, 'permissions' => 1],
		]);

		self::assertSame(20, $updated->getPages()[301]['content'][501]['order']);
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
