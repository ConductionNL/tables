<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Db;

use OCA\Tables\Db\ContextMapper;
use OCA\Tables\Db\MenuItemMapper;
use OCA\Tables\Helper\GroupHelper;
use OCA\Tables\Helper\UserHelper;
use OCA\Tables\Tests\Unit\Database\DatabaseTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Covers the SQL that finds applications by technical name, which is unique per owner only.
 */
class ContextMapperTechnicalNameTest extends DatabaseTestCase {
	private ContextMapper $contextMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->cleanupTablesData();
		$userHelper = $this->createMock(UserHelper::class);
		$userHelper->method('getGroupIdsForUser')->willReturn([]);
		$this->contextMapper = new ContextMapper(
			$this->connectionAdapter,
			$userHelper,
			$this->createMock(GroupHelper::class),
			new MenuItemMapper($this->connectionAdapter),
		);
	}

	public function testTheOwnApplicationWinsOverOneSharedWithTheSameName(): void {
		$shared = $this->createContext('bob', 'intake');
		$this->shareWith($shared, 'bob', 'alice');
		$own = $this->createContext('alice', 'intake');

		$this->assertSame($own, $this->contextMapper->findIdByTechnicalName('intake', 'alice'));
		$this->assertSame($shared, $this->contextMapper->findIdByTechnicalName('intake', 'bob'));
	}

	public function testAnApplicationSharedWithTheUserIsFoundByItsName(): void {
		$shared = $this->createContext('bob', 'intake');
		$this->shareWith($shared, 'bob', 'alice');

		$this->assertSame($shared, $this->contextMapper->findIdByTechnicalName('intake', 'alice'));
	}

	public function testAnApplicationOfSomeoneElseIsNotFound(): void {
		$this->createContext('bob', 'intake');

		$this->assertNull($this->contextMapper->findIdByTechnicalName('intake', 'alice'));
		$this->assertNull($this->contextMapper->findIdByTechnicalName('reports', 'bob'));
	}

	public function testANameIsTakenPerOwnerAndNotByTheApplicationItself(): void {
		$intake = $this->createContext('alice', 'intake');

		$this->assertTrue($this->contextMapper->isTechnicalNameTaken('intake', 'alice'));
		$this->assertFalse($this->contextMapper->isTechnicalNameTaken('intake', 'alice', $intake));
		$this->assertFalse($this->contextMapper->isTechnicalNameTaken('intake', 'bob'));
	}

	private function createContext(string $ownerId, ?string $technicalName): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('tables_contexts_context')
			->values([
				'name' => $qb->createNamedParameter('Intake'),
				'icon' => $qb->createNamedParameter('star'),
				'description' => $qb->createNamedParameter(''),
				'owner_id' => $qb->createNamedParameter($ownerId),
				'owner_type' => $qb->createNamedParameter(0),
				'technical_name' => $qb->createNamedParameter($technicalName),
			])
			->executeStatement();
		return (int)$this->connection->lastInsertId();
	}

	private function shareWith(int $contextId, string $sender, string $receiver): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('tables_shares')
			->values([
				'sender' => $qb->createNamedParameter($sender),
				'receiver' => $qb->createNamedParameter($receiver),
				'receiver_type' => $qb->createNamedParameter('user'),
				'node_id' => $qb->createNamedParameter($contextId),
				'node_type' => $qb->createNamedParameter('context'),
				'permission_read' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
				'permission_create' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
				'permission_update' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
				'permission_delete' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
				'permission_manage' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
				'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
				'last_edit_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			])
			->executeStatement();
	}
}
