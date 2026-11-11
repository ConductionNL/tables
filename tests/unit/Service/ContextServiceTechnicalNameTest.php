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
use OCA\Tables\Db\PageContentMapper;
use OCA\Tables\Db\PageMapper;
use OCA\Tables\Db\TableMapper;
use OCA\Tables\Db\ViewMapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Service\Support\AuditLogServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ContextServiceTechnicalNameTest extends TestCase {
	private ContextService $service;
	private ContextMapper&MockObject $contextMapper;
	private ContextNodeRelationMapper&MockObject $contextNodeRelMapper;
	private IUserManager&MockObject $userManager;

	protected function setUp(): void {
		parent::setUp();

		$this->contextMapper = $this->createMock(ContextMapper::class);
		$this->contextNodeRelMapper = $this->createMock(ContextNodeRelationMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);

		$this->service = new ContextService(
			$this->contextMapper,
			$this->contextNodeRelMapper,
			$this->createMock(PageMapper::class),
			$this->createMock(PageContentMapper::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(PermissionsService::class),
			$this->userManager,
			$this->createMock(AuditLogServiceInterface::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ShareService::class),
			false,
			$this->createMock(INavigationManager::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(TableMapper::class),
			$this->createMock(ViewMapper::class),
		);
	}

	public function testDefaultNodeNameDependsOnTheNodeType(): void {
		$this->assertSame('table_5', ContextService::defaultNodeTechnicalName(Application::NODE_TYPE_TABLE, 5));
		$this->assertSame('view_5', ContextService::defaultNodeTechnicalName(Application::NODE_TYPE_VIEW, 5));
	}

	public function testUniqueNameTakesTheLowestFreeSuffix(): void {
		$this->assertSame('table_5', ContextService::uniqueTechnicalName('table_5', []));
		$this->assertSame('table_5_3', ContextService::uniqueTechnicalName('table_5', ['table_5' => true, 'table_5_2' => true]));
	}

	/**
	 * @dataProvider invalidTechnicalNames
	 */
	public function testCreateRefusesAnInvalidName(string $technicalName): void {
		$this->contextMapper->expects($this->never())->method('insert');

		$this->expectException(BadRequestError::class);
		$this->service->create('CRM', 'icon', '', [], 'alice', Application::OWNER_TYPE_USER, $technicalName);
	}

	public static function invalidTechnicalNames(): array {
		return [
			'empty' => [''],
			'starts with a digit' => ['1crm'],
			'upper case' => ['Crm'],
			'dash' => ['my-crm'],
			'too long' => [str_repeat('a', 201)],
		];
	}

	public function testCreateRefusesANameTheOwnerAlreadyUses(): void {
		$this->contextMapper->method('findTechnicalNamesForOwner')
			->with(Application::OWNER_TYPE_USER, 'alice')
			->willReturn(['crm' => true]);
		$this->contextMapper->expects($this->never())->method('insert');

		$this->expectException(BadRequestError::class);
		$this->service->create('CRM', 'icon', '', [], 'alice', Application::OWNER_TYPE_USER, 'crm');
	}

	public function testUpdateRenamesTheContext(): void {
		$context = $this->context(7, 'application_7', 'alice');
		$this->contextMapper->method('findById')->willReturn($context);
		$this->contextMapper->method('findTechnicalNamesForOwner')->willReturn(['application_7' => true]);
		$this->contextMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$updated = $this->service->update(7, 'alice', null, null, null, null, 'crm');

		$this->assertSame('crm', $updated->getTechnicalName());
	}

	public function testUpdateRefusesANameTheOwnerAlreadyUses(): void {
		$this->contextMapper->method('findById')->willReturn($this->context(7, 'application_7', 'alice'));
		$this->contextMapper->method('findTechnicalNamesForOwner')->willReturn(['application_7' => true, 'crm' => true]);
		$this->contextMapper->expects($this->never())->method('update');

		$this->expectException(BadRequestError::class);
		$this->service->update(7, 'alice', null, null, null, null, 'crm');
	}

	public function testTransferRefusesAnOwnerWhoUsesTheNameAlready(): void {
		$this->contextMapper->method('findById')->willReturn($this->context(7, 'crm', 'alice'));
		$this->userManager->method('userExists')->willReturn(true);
		$this->contextMapper->method('findTechnicalNamesForOwner')
			->with(Application::OWNER_TYPE_USER, 'bob')
			->willReturn(['crm' => true]);
		$this->contextMapper->expects($this->never())->method('update');

		$this->expectException(BadRequestError::class);
		$this->service->transfer(7, 'bob', Application::OWNER_TYPE_USER);
	}

	public function testAddNodeGetsTheDefaultNameWithASuffixWhenTaken(): void {
		$this->contextNodeRelMapper->method('findTechnicalNamesByContextId')->with(7)->willReturn(['table_5' => true]);
		$this->contextNodeRelMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$relation = $this->service->addNodeToContext($this->context(7, 'crm', 'alice'), 5, Application::NODE_TYPE_TABLE, Application::PERMISSION_READ);

		$this->assertSame('table_5_2', $relation->getTechnicalName());
	}

	public function testAddNodeKeepsAFreeGivenName(): void {
		$this->contextNodeRelMapper->method('findTechnicalNamesByContextId')->willReturn(['table_5' => true]);
		$this->contextNodeRelMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$relation = $this->service->addNodeToContext($this->context(7, 'crm', 'alice'), 6, Application::NODE_TYPE_TABLE, Application::PERMISSION_READ, 'customers');

		$this->assertSame('customers', $relation->getTechnicalName());
	}

	public function testAddNodeRefusesAGivenNameThatIsTaken(): void {
		$this->contextNodeRelMapper->method('findTechnicalNamesByContextId')->willReturn(['customers' => true]);
		$this->contextNodeRelMapper->expects($this->never())->method('insert');

		$this->expectException(BadRequestError::class);
		$this->service->addNodeToContext($this->context(7, 'crm', 'alice'), 6, Application::NODE_TYPE_TABLE, Application::PERMISSION_READ, 'customers');
	}

	public function testResolveNodeFindsTheNodeOfTheOnlyMatchingContext(): void {
		$relation = new ContextNodeRelation();
		$this->contextMapper->method('findAllByTechnicalName')->with('crm', 'alice')->willReturn([$this->context(7, 'crm', 'alice')]);
		$this->contextNodeRelMapper->method('findByContextAndTechnicalName')->with(7, 'customers')->willReturn($relation);

		$this->assertSame($relation, $this->service->resolveNode('crm', 'customers', 'alice'));
	}

	public function testResolveNodeRefusesAnAmbiguousContextName(): void {
		$this->contextMapper->method('findAllByTechnicalName')->willReturn([
			$this->context(7, 'crm', 'alice'),
			$this->context(8, 'crm', 'bob'),
		]);

		$this->expectException(BadRequestError::class);
		$this->service->resolveNode('crm', 'customers', 'alice');
	}

	public function testResolveNodeReportsAnUnknownContext(): void {
		$this->contextMapper->method('findAllByTechnicalName')->willReturn([]);

		$this->expectException(NotFoundError::class);
		$this->service->resolveNode('crm', 'customers', 'alice');
	}

	public function testResolveNodeReportsAnUnknownNode(): void {
		$this->contextMapper->method('findAllByTechnicalName')->willReturn([$this->context(7, 'crm', 'alice')]);
		$this->contextNodeRelMapper->method('findByContextAndTechnicalName')->willThrowException(new DoesNotExistException(''));

		$this->expectException(NotFoundError::class);
		$this->service->resolveNode('crm', 'customers', 'alice');
	}

	private function context(int $id, string $technicalName, string $ownerId): Context {
		$context = new Context();
		$context->setId($id);
		$context->setTechnicalName($technicalName);
		$context->setOwnerId($ownerId);
		$context->setOwnerType(Application::OWNER_TYPE_USER);
		$context->resetUpdatedFields();
		return $context;
	}
}
