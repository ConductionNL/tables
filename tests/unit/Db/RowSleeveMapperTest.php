<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Db;

use OCA\Tables\Db\RowSleeve;
use OCA\Tables\Db\RowSleeveMapper;
use OCA\Tables\Tests\Unit\Database\DatabaseTestCase;
use OCA\Tables\Vendor\Symfony\Component\Uid\Uuid;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

class RowSleeveMapperTest extends DatabaseTestCase {
	private RowSleeveMapper $mapper;
	/** @var int[] */
	private array $tableIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new RowSleeveMapper($this->connectionAdapter, $this->createMock(LoggerInterface::class));
	}

	protected function tearDown(): void {
		foreach ($this->tableIds as $tableId) {
			$this->mapper->deleteAllForTable($tableId);
			$qb = $this->connection->getQueryBuilder();
			$qb->delete('tables_tables')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($tableId)))
				->executeStatement();
		}
		parent::tearDown();
	}

	private function createTable(): int {
		$tableId = $this->createTestTable()['id'];
		$this->tableIds[] = $tableId;
		return $tableId;
	}

	private function insertSleeve(int $tableId, ?string $uuid = null): RowSleeve {
		$sleeve = new RowSleeve();
		$sleeve->setTableId($tableId);
		$sleeve->setUuid($uuid);
		$sleeve->setCreatedBy('user1');
		$sleeve->setCreatedAt('2026-10-14 10:00:00');
		$sleeve->setLastEditBy('user1');
		$sleeve->setLastEditAt('2026-10-14 10:00:00');
		return $this->mapper->insert($sleeve);
	}

	public function testInsertAssignsAndStoresAUuid(): void {
		$sleeve = $this->insertSleeve($this->createTable());

		$this->assertNotNull($sleeve->getUuid());
		$this->assertTrue(Uuid::isValid($sleeve->getUuid()));
		$this->assertSame($sleeve->getUuid(), $this->mapper->find($sleeve->getId())->getUuid());
	}

	public function testInsertKeepsAGivenUuid(): void {
		$uuid = Uuid::v7()->toRfc4122();
		$sleeve = $this->insertSleeve($this->createTable(), $uuid);

		$this->assertSame($uuid, $this->mapper->find($sleeve->getId())->getUuid());
	}

	public function testFindMultipleReturnsTheUuid(): void {
		$sleeve = $this->insertSleeve($this->createTable());

		$found = $this->mapper->findMultiple([$sleeve->getId()]);

		$this->assertCount(1, $found);
		$this->assertSame($sleeve->getUuid(), $found[0]->getUuid());
	}

	public function testFindIdByUuidWithinTheTable(): void {
		$tableId = $this->createTable();
		$sleeve = $this->insertSleeve($tableId);

		$this->assertSame($sleeve->getId(), $this->mapper->findIdByUuid($sleeve->getUuid(), $tableId));
	}

	public function testFindIdByUuidDoesNotFindARowOfAnotherTable(): void {
		$sleeve = $this->insertSleeve($this->createTable());
		$otherTableId = $this->createTable();

		$this->assertNull($this->mapper->findIdByUuid($sleeve->getUuid(), $otherTableId));
	}

	public function testFindIdByUuidWithoutTableLooksInAllTables(): void {
		$sleeve = $this->insertSleeve($this->createTable());

		$this->assertSame($sleeve->getId(), $this->mapper->findIdByUuid($sleeve->getUuid()));
	}

	public function testFindIdByUuidReturnsNullForAnUnknownUuid(): void {
		$this->assertNull($this->mapper->findIdByUuid(Uuid::v7()->toRfc4122()));
	}

	public function testTheUuidIsUniqueAcrossTables(): void {
		$uuid = Uuid::v7()->toRfc4122();
		$this->insertSleeve($this->createTable(), $uuid);

		$this->expectException(Exception::class);
		$this->insertSleeve($this->createTable(), $uuid);
	}

	public function testFindUuidsByIdsReturnsTheUuidPerRowId(): void {
		$tableId = $this->createTable();
		$first = $this->insertSleeve($tableId);
		$second = $this->insertSleeve($tableId);

		$uuids = $this->mapper->findUuidsByIds([$first->getId(), $second->getId(), $first->getId()]);

		ksort($uuids);
		$this->assertSame([$first->getId() => $first->getUuid(), $second->getId() => $second->getUuid()], $uuids);
	}

	public function testFindUuidsByIdsGivesNullForARowWithoutAUuidAndLeavesOutMissingRows(): void {
		$tableId = $this->createTable();
		$withUuid = $this->insertSleeve($tableId);
		$withoutUuid = $this->insertSleeve($tableId);
		$qb = $this->connection->getQueryBuilder();
		$qb->update('tables_row_sleeves')
			->set('uuid', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($withoutUuid->getId())))
			->executeStatement();

		$uuids = $this->mapper->findUuidsByIds([$withUuid->getId(), $withoutUuid->getId(), $withoutUuid->getId() + 1000]);

		ksort($uuids);
		$this->assertSame([$withUuid->getId() => $withUuid->getUuid(), $withoutUuid->getId() => null], $uuids);
	}

	public function testFindUuidsByIdsReadsEveryChunk(): void {
		$tableId = $this->createTable();
		$first = $this->insertSleeve($tableId);
		$last = $this->insertSleeve($tableId);
		$missingIds = range($last->getId() + 1, $last->getId() + 1500);

		$uuids = $this->mapper->findUuidsByIds([$first->getId(), ...$missingIds, $last->getId()]);

		ksort($uuids);
		$this->assertSame([$first->getId() => $first->getUuid(), $last->getId() => $last->getUuid()], $uuids);
	}
}
