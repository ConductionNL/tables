<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use OCA\Tables\AppInfo\Application;
use OCA\Tables\Db\Context;
use OCA\Tables\Db\ContextMapper;
use OCA\Tables\Db\ContextNodeRelation;
use OCA\Tables\Db\ContextNodeRelationMapper;
use OCA\Tables\Db\Page;
use OCA\Tables\Db\PageContentMapper;
use OCA\Tables\Db\PageMapper;
use OCA\Tables\Db\TableMapper;
use OCA\Tables\Db\ViewMapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Service\Support\AuditLogServiceInterface;
use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ContextServiceSlugTest extends TestCase {
	private ContextService $service;
	private ContextMapper&MockObject $contextMapper;
	private ContextNodeRelationMapper&MockObject $contextNodeRelMapper;
	private IUserManager&MockObject $userManager;
	private ShareService&MockObject $shareService;
	private PageMapper&MockObject $pageMapper;
	private IDBConnection&MockObject $dbc;

	protected function setUp(): void {
		parent::setUp();

		$this->contextMapper = $this->createMock(ContextMapper::class);
		$this->contextNodeRelMapper = $this->createMock(ContextNodeRelationMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->shareService = $this->createMock(ShareService::class);
		$this->pageMapper = $this->createMock(PageMapper::class);
		$this->dbc = $this->createMock(IDBConnection::class);

		$this->service = new ContextService(
			$this->contextMapper,
			$this->contextNodeRelMapper,
			$this->pageMapper,
			$this->createMock(PageContentMapper::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(PermissionsService::class),
			$this->userManager,
			$this->createMock(AuditLogServiceInterface::class),
			$this->dbc,
			$this->shareService,
			false,
			$this->createMock(INavigationManager::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(TableMapper::class),
			$this->createMock(ViewMapper::class),
		);
	}

	public function testDefaultNodeNameDependsOnTheNodeType(): void {
		$this->assertSame('table-5', ContextService::defaultNodeSlug(Application::NODE_TYPE_TABLE, 5));
		$this->assertSame('view-5', ContextService::defaultNodeSlug(Application::NODE_TYPE_VIEW, 5));
	}

	public function testUniqueNameTakesTheLowestFreeSuffix(): void {
		$this->assertSame('table-5', ContextService::uniqueSlug('table-5', []));
		$this->assertSame('table-5-3', ContextService::uniqueSlug('table-5', ['table-5' => true, 'table-5-2' => true]));
	}

	/**
	 * @dataProvider invalidSlugs
	 */
	public function testCreateRefusesAnInvalidName(string $slug): void {
		$this->contextMapper->expects($this->never())->method('insert');

		$this->expectException(BadRequestError::class);
		$this->service->create('CRM', 'icon', '', [], 'alice', Application::OWNER_TYPE_USER, $slug);
	}

	public static function invalidSlugs(): array {
		return [
			'empty' => [''],
			'starts with a digit' => ['1crm'],
			'upper case' => ['Crm'],
			'underscore' => ['my_crm'],
			'leading dash' => ['-crm'],
			'trailing dash' => ['crm-'],
			'double dash' => ['my--crm'],
			'too long' => [str_repeat('a', 201)],
		];
	}

	/**
	 * @dataProvider validSlugs
	 */
	public function testCreateAcceptsAKebabCaseSlug(string $slug): void {
		$this->contextMapper->method('findSlugsForOwner')->willReturn([]);
		$this->contextMapper->expects($this->once())->method('insert')->willReturnCallback(static function (Context $context): Context {
			$context->setId(7);
			return $context;
		});
		$this->pageMapper->method('insert')->willReturnCallback(static function (Page $page): Page {
			$page->setId(1);
			return $page;
		});

		$context = $this->service->create('CRM', 'icon', '', [], 'alice', Application::OWNER_TYPE_USER, $slug);

		$this->assertSame($slug, $context->getSlug());
	}

	public static function validSlugs(): array {
		return [
			'single word' => ['crm'],
			'with dashes' => ['my-crm-2'],
			'longest' => [str_repeat('a', 200)],
		];
	}

	public function testCreateRefusesANameTheOwnerAlreadyUses(): void {
		$this->contextMapper->method('findSlugsForOwner')
			->with(Application::OWNER_TYPE_USER, 'alice')
			->willReturn(['crm' => true]);
		$this->contextMapper->expects($this->never())->method('insert');

		$this->expectException(BadRequestError::class);
		$this->service->create('CRM', 'icon', '', [], 'alice', Application::OWNER_TYPE_USER, 'crm');
	}

	public function testUpdateRenamesTheContext(): void {
		$context = $this->context(7, 'application-7', 'alice');
		$this->contextMapper->method('findById')->willReturn($context);
		$this->contextMapper->method('findSlugsForOwner')->willReturn(['application-7' => true]);
		$this->contextMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$updated = $this->service->update(7, 'alice', null, null, null, null, 'crm');

		$this->assertSame('crm', $updated->getSlug());
	}

	public function testUpdateRefusesToRenameASharedContext(): void {
		$this->contextMapper->method('findById')->willReturn($this->context(7, 'application-7', 'alice'));
		$this->shareService->method('isContextShared')->willReturn(true);
		$this->contextMapper->expects($this->never())->method('update');

		$this->expectException(BadRequestError::class);
		$this->service->update(7, 'alice', null, null, null, null, 'crm');
	}

	public function testUpdateOfASharedContextKeepingItsSlugSucceeds(): void {
		$this->contextMapper->method('findById')->willReturn($this->context(7, 'crm', 'alice'));
		$this->shareService->expects($this->never())->method('isContextShared');
		$this->contextMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$updated = $this->service->update(7, 'alice', 'CRM 2', null, null, null, 'crm');

		$this->assertSame('crm', $updated->getSlug());
		$this->assertSame('CRM 2', $updated->getName());
	}

	public function testUpdateRefusesToRenameANodeOfASharedContext(): void {
		$this->contextMapper->method('findById')->willReturn($this->contextWithTable(7, 5, 'customers'));
		$this->contextNodeRelMapper->method('findById')->willReturn(new ContextNodeRelation());
		$this->shareService->method('isContextShared')->willReturn(true);
		$this->contextNodeRelMapper->expects($this->never())->method('update');

		$this->expectException(BadRequestError::class);
		$this->service->update(7, 'alice', null, null, null, [
			['id' => 5, 'type' => Application::NODE_TYPE_TABLE, 'permissions' => Application::PERMISSION_READ, 'order' => 10, 'slug' => 'clients'],
		]);
	}

	public function testUpdateRenamesANodeOfAnUnsharedContext(): void {
		$this->contextMapper->method('findById')->willReturn($this->contextWithTable(7, 5, 'customers'));
		$this->contextNodeRelMapper->method('findById')->willReturn(new ContextNodeRelation());
		$this->contextNodeRelMapper->method('findSlugsByContextId')->willReturn(['customers' => true]);
		$this->shareService->method('isContextShared')->willReturn(false);
		$writtenSlugs = [];
		$this->contextNodeRelMapper->method('update')
			->willReturnCallback(static function (ContextNodeRelation $relation) use (&$writtenSlugs): ContextNodeRelation {
				$writtenSlugs[] = $relation->getSlug();
				return $relation;
			});
		$this->contextMapper->method('update')->willReturnArgument(0);

		$this->service->update(7, 'alice', null, null, null, [
			['id' => 5, 'type' => Application::NODE_TYPE_TABLE, 'permissions' => Application::PERMISSION_READ, 'order' => 10, 'slug' => 'clients'],
		]);

		$this->assertCount(2, $writtenSlugs);
		$this->assertStringStartsWith('_', $writtenSlugs[0]);
		$this->assertSame('clients', $writtenSlugs[1]);
	}

	public function testUpdateRefusesANameTheOwnerAlreadyUses(): void {
		$this->contextMapper->method('findById')->willReturn($this->context(7, 'application-7', 'alice'));
		$this->contextMapper->method('findSlugsForOwner')->willReturn(['application-7' => true, 'crm' => true]);
		$this->contextMapper->expects($this->never())->method('update');

		$this->expectException(BadRequestError::class);
		$this->service->update(7, 'alice', null, null, null, null, 'crm');
	}

	public function testTransferRefusesAnOwnerWhoUsesTheNameAlready(): void {
		$this->contextMapper->method('findById')->willReturn($this->context(7, 'crm', 'alice'));
		$this->userManager->method('userExists')->willReturn(true);
		$this->contextMapper->method('findSlugsForOwner')
			->with(Application::OWNER_TYPE_USER, 'bob')
			->willReturn(['crm' => true]);
		$this->contextMapper->expects($this->never())->method('update');

		$this->expectException(BadRequestError::class);
		$this->service->transfer(7, 'bob', Application::OWNER_TYPE_USER);
	}

	public function testAddNodeGetsTheDefaultNameWithASuffixWhenTaken(): void {
		$this->contextNodeRelMapper->method('findSlugsByContextId')->with(7)->willReturn(['table-5' => true]);
		$this->contextNodeRelMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$relation = $this->service->addNodeToContext($this->context(7, 'crm', 'alice'), 5, Application::NODE_TYPE_TABLE, Application::PERMISSION_READ);

		$this->assertSame('table-5-2', $relation->getSlug());
	}

	public function testAddNodeKeepsAFreeGivenName(): void {
		$this->contextNodeRelMapper->method('findSlugsByContextId')->willReturn(['table-5' => true]);
		$this->contextNodeRelMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$relation = $this->service->addNodeToContext($this->context(7, 'crm', 'alice'), 6, Application::NODE_TYPE_TABLE, Application::PERMISSION_READ, 'customers');

		$this->assertSame('customers', $relation->getSlug());
	}

	public function testAddNodeRefusesAGivenNameThatIsTaken(): void {
		$this->contextNodeRelMapper->method('findSlugsByContextId')->willReturn(['customers' => true]);
		$this->contextNodeRelMapper->expects($this->never())->method('insert');

		$this->expectException(BadRequestError::class);
		$this->service->addNodeToContext($this->context(7, 'crm', 'alice'), 6, Application::NODE_TYPE_TABLE, Application::PERMISSION_READ, 'customers');
	}

	public function testUpdateLetsTwoNodesSwapTheirSlugs(): void {
		$this->contextMapper->method('findById')->willReturn($this->contextWithTables(7, [5 => 'customers', 6 => 'clients']));
		$this->contextNodeRelMapper->method('findById')->willReturnCallback(fn (int $id): ContextNodeRelation => $this->relation($id));
		$this->shareService->method('isContextShared')->willReturn(false);
		$writes = [];
		$this->contextNodeRelMapper->method('update')
			->willReturnCallback(static function (ContextNodeRelation $relation) use (&$writes): ContextNodeRelation {
				$writes[] = [$relation->getId(), $relation->getSlug()];
				return $relation;
			});
		$this->contextMapper->method('update')->willReturnArgument(0);

		$this->service->update(7, 'alice', null, null, null, [
			['id' => 5, 'type' => Application::NODE_TYPE_TABLE, 'permissions' => Application::PERMISSION_READ, 'order' => 10, 'slug' => 'clients'],
			['id' => 6, 'type' => Application::NODE_TYPE_TABLE, 'permissions' => Application::PERMISSION_READ, 'order' => 20, 'slug' => 'customers'],
		]);

		$this->assertCount(4, $writes);
		$this->assertStringStartsWith('_', $writes[0][1]);
		$this->assertStringStartsWith('_', $writes[1][1]);
		$this->assertSame([[1, 'clients'], [2, 'customers']], [$writes[2], $writes[3]]);
	}

	public function testUpdateGivesAKeptNodeTheSlugOfANodeRemovedInTheSameRequest(): void {
		$this->contextMapper->method('findById')->willReturn($this->contextWithTables(7, [5 => 'customers', 6 => 'clients']));
		$this->contextNodeRelMapper->method('findById')->willReturnCallback(fn (int $id): ContextNodeRelation => $this->relation($id));
		$this->shareService->method('isContextShared')->willReturn(false);
		$writes = [];
		$this->contextNodeRelMapper->method('delete')
			->willReturnCallback(static function (ContextNodeRelation $relation) use (&$writes): ContextNodeRelation {
				$writes[] = 'delete ' . $relation->getId();
				return $relation;
			});
		$this->contextNodeRelMapper->method('update')
			->willReturnCallback(static function (ContextNodeRelation $relation) use (&$writes): ContextNodeRelation {
				$writes[] = 'update ' . $relation->getId() . ' ' . $relation->getSlug();
				return $relation;
			});
		$this->contextMapper->method('update')->willReturnArgument(0);

		$this->service->update(7, 'alice', null, null, null, [
			['id' => 6, 'type' => Application::NODE_TYPE_TABLE, 'permissions' => Application::PERMISSION_READ, 'order' => 10, 'slug' => 'customers'],
		]);

		$this->assertSame('delete 1', $writes[0]);
		$this->assertSame('update 2 customers', $writes[2]);
	}

	public function testUpdateRefusesTwoNodesWithTheSameSlugBeforeWritingAnything(): void {
		$this->contextMapper->method('findById')->willReturn($this->contextWithTables(7, [5 => 'customers', 6 => 'clients']));
		$this->shareService->method('isContextShared')->willReturn(false);
		$this->dbc->expects($this->never())->method('beginTransaction');
		$this->contextNodeRelMapper->expects($this->never())->method('update');

		$this->expectException(BadRequestError::class);
		$this->service->update(7, 'alice', null, null, null, [
			['id' => 5, 'type' => Application::NODE_TYPE_TABLE, 'permissions' => Application::PERMISSION_MANAGE, 'order' => 10],
			['id' => 6, 'type' => Application::NODE_TYPE_TABLE, 'permissions' => Application::PERMISSION_READ, 'order' => 20, 'slug' => 'customers'],
		]);
	}

	public function testUpdateRefusesAnAddedNodeTakingTheSlugOfAKeptNodeBeforeWritingAnything(): void {
		$this->contextMapper->method('findById')->willReturn($this->contextWithTables(7, [5 => 'customers']));
		$this->dbc->expects($this->never())->method('beginTransaction');
		$this->contextNodeRelMapper->expects($this->never())->method('insert');

		$this->expectException(BadRequestError::class);
		$this->service->update(7, 'alice', null, null, null, [
			['id' => 5, 'type' => Application::NODE_TYPE_TABLE, 'permissions' => Application::PERMISSION_READ, 'order' => 10],
			['id' => 6, 'type' => Application::NODE_TYPE_TABLE, 'permissions' => Application::PERMISSION_READ, 'order' => 20, 'slug' => 'customers'],
		]);
	}

	public function testUpdateRollsBackEarlierChangesWhenALaterWriteFails(): void {
		$this->contextMapper->method('findById')->willReturn($this->contextWithTables(7, [5 => 'customers', 6 => 'clients']));
		$this->contextNodeRelMapper->method('findById')->willReturnCallback(fn (int $id): ContextNodeRelation => $this->relation($id));
		$this->contextNodeRelMapper->method('update')->willReturnArgument(0);
		$this->contextMapper->method('update')->willThrowException($this->uniqueConstraintViolation());
		$this->dbc->expects($this->once())->method('beginTransaction');
		$this->dbc->expects($this->once())->method('rollBack');
		$this->dbc->expects($this->never())->method('commit');

		$this->expectException(BadRequestError::class);
		$this->service->update(7, 'alice', null, null, null, [
			['id' => 5, 'type' => Application::NODE_TYPE_TABLE, 'permissions' => Application::PERMISSION_MANAGE, 'order' => 10],
			['id' => 6, 'type' => Application::NODE_TYPE_TABLE, 'permissions' => Application::PERMISSION_READ, 'order' => 20],
		], 'crm-2');
	}

	/**
	 * @param array<int, string> $nodeSlugsByTableId
	 */
	private function contextWithTables(int $contextId, array $nodeSlugsByTableId): Context {
		$context = $this->context($contextId, 'crm', 'alice');
		$nodes = [];
		$relationId = 1;
		foreach ($nodeSlugsByTableId as $tableId => $nodeSlug) {
			$nodes[$relationId] = ['id' => $relationId, 'node_id' => $tableId, 'node_type' => Application::NODE_TYPE_TABLE, 'permissions' => Application::PERMISSION_READ, 'slug' => $nodeSlug];
			$relationId++;
		}
		$context->setNodes($nodes);
		$context->setPages([]);
		return $context;
	}

	private function relation(int $id): ContextNodeRelation {
		$relation = new ContextNodeRelation();
		$relation->setId($id);
		$relation->setPermissions(Application::PERMISSION_READ);
		return $relation;
	}

	private function uniqueConstraintViolation(): Exception {
		$exception = $this->createMock(Exception::class);
		$exception->method('getReason')->willReturn(Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		return $exception;
	}

	private function contextWithTable(int $contextId, int $tableId, string $nodeSlug): Context {
		$context = $this->context($contextId, 'crm', 'alice');
		$context->setNodes([
			1 => ['id' => 1, 'node_id' => $tableId, 'node_type' => Application::NODE_TYPE_TABLE, 'permissions' => Application::PERMISSION_READ, 'slug' => $nodeSlug],
		]);
		$context->setPages([]);
		return $context;
	}

	private function context(int $id, string $slug, string $ownerId): Context {
		$context = new Context();
		$context->setId($id);
		$context->setSlug($slug);
		$context->setOwnerId($ownerId);
		$context->setOwnerType(Application::OWNER_TYPE_USER);
		$context->resetUpdatedFields();
		return $context;
	}
}
