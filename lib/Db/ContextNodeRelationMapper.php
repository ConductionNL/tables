<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<ContextNodeRelation> */
class ContextNodeRelationMapper extends QBMapper {
	protected string $table = 'tables_contexts_rel_context_node';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, $this->table, ContextNodeRelation::class);
	}

	public function deleteAllByContextId(int $contextId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->tableName)
			->where($qb->expr()->eq('context_id', $qb->createNamedParameter($contextId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	public function getRelIdsForNode(int $nodeId, int $nodeType): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->table)
			->where($qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId)))
			->andWhere($qb->expr()->eq('node_type', $qb->createNamedParameter($nodeType)));
		$result = $qb->executeQuery();
		$nodeRelIds = [];
		while ($row = $result->fetchAssociative()) {
			$nodeRelIds[] = (int)$row['id'];
		}
		return $nodeRelIds;
	}

	public function deleteByNodeRelIds(array $nodeRelIds): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->table)
			->where($qb->expr()->in('id', $qb->createNamedParameter($nodeRelIds, IQueryBuilder::PARAM_INT_ARRAY), ':nodeRelIds'));
		$qb->executeStatement();
	}

	/**
	 * @throws MultipleObjectsReturnedException
	 * @throws DoesNotExistException
	 * @throws Exception
	 */
	public function findById(int $nodeRelId): ContextNodeRelation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($nodeRelId)));

		$row = $this->findOneQuery($qb);
		return $this->mapRowToEntity($row);
	}

	/**
	 * @return array<string, true> the technical names used in the application
	 * @throws Exception
	 */
	public function findTechnicalNamesByContextId(int $contextId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('technical_name')
			->from($this->tableName)
			->where($qb->expr()->eq('context_id', $qb->createNamedParameter($contextId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$names = [];
		while ($row = $result->fetchAssociative()) {
			$names[(string)$row['technical_name']] = true;
		}
		$result->closeCursor();
		return $names;
	}

	/**
	 * @throws MultipleObjectsReturnedException
	 * @throws DoesNotExistException
	 * @throws Exception
	 */
	public function findByContextAndTechnicalName(int $contextId, string $technicalName): ContextNodeRelation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('context_id', $qb->createNamedParameter($contextId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('technical_name', $qb->createNamedParameter($technicalName)));

		return $this->findEntity($qb);
	}
}
