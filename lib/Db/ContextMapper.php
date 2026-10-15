<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Db;

use OCA\Tables\AppInfo\Application;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Helper\GroupHelper;
use OCA\Tables\Helper\UserHelper;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Context> */
class ContextMapper extends QBMapper {
	private const DB_CHUNK_SIZE = 1_000;

	protected string $table = 'tables_contexts_context';

	public function __construct(
		IDBConnection $db,
		protected UserHelper $userHelper,
		protected GroupHelper $groupHelper,
	) {
		parent::__construct($db, $this->table, Context::class);
	}

	protected function getFindContextBaseQuery(?string $userId): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();

		$qb->select(
			'c.*',
			'p.id as page_id', 'p.page_type', 'p.title as page_title', 'p.technical_name as page_technical_name',
			'pc.id as content_id', 'pc.order', 'pc.node_rel_id as content_node_rel_id', 'pc.type as content_type',
			'pc.configuration as content_configuration', 'pc.grid_x', 'pc.grid_y', 'pc.grid_width', 'pc.grid_height',
			'n.display_mode as display_mode_default',
			's.id as share_id', 's.receiver', 's.receiver_type'
		)
			->from($this->table, 'c');

		if ($userId !== null) {
			$this->applyOwnedOrSharedQuery($qb, $userId);
			$qb->addSelect('n2.display_mode');
			$qb->leftJoin('s', 'tables_contexts_navigation', 'n2', $qb->expr()->andX(
				$qb->expr()->eq('s.id', 'n2.share_id'),
				$qb->expr()->eq('n2.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)),
			));
		} else {
			$qb->leftJoin('c', 'tables_shares', 's', $qb->expr()->andX(
				$qb->expr()->eq('c.id', 's.node_id'),
				$qb->expr()->eq('s.node_type', $qb->createNamedParameter('context')),
			));
		}

		$qb->leftJoin('c', 'tables_contexts_page', 'p', $qb->expr()->eq('c.id', 'p.context_id'));
		$qb->leftJoin('p', 'tables_contexts_page_content', 'pc', $qb->expr()->eq('p.id', 'pc.page_id'));
		$qb->leftJoin('s', 'tables_contexts_navigation', 'n', $qb->expr()->andX(
			$qb->expr()->eq('s.id', 'n.share_id'),
			$qb->expr()->eq('n.user_id', $qb->createNamedParameter('')),
		));

		$qb->orderBy('p.id', 'ASC')
			->addOrderBy('pc.order', 'ASC')
			->addOrderBy('pc.id', 'ASC');

		return $qb;
	}

	/**
	 * The node relations of the given contexts, by context id and relation id.
	 * With a node type and id, only the relations to that node are returned.
	 *
	 * @param int[] $contextIds
	 * @return array<int, array<int, array{id: int, node_id: int, node_type: int, permissions: int}>>
	 * @throws Exception
	 */
	protected function findNodesForContexts(array $contextIds, ?int $nodeType = null, ?int $nodeId = null): array {
		$nodes = [];
		if ($contextIds === []) {
			return $nodes;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'context_id', 'node_id', 'node_type', 'permissions')
			->from('tables_contexts_rel_context_node')
			->where($qb->expr()->in('context_id', $qb->createParameter('contextIds')))
			->orderBy('id', 'ASC');
		if ($nodeType !== null && $nodeId !== null) {
			$qb->andWhere($qb->expr()->eq('node_type', $qb->createNamedParameter($nodeType, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT)));
		}
		foreach (array_chunk($contextIds, self::DB_CHUNK_SIZE) as $chunk) {
			$qb->setParameter('contextIds', $chunk, IQueryBuilder::PARAM_INT_ARRAY);
			$result = $qb->executeQuery();
			while ($row = $result->fetchAssociative()) {
				$nodes[(int)$row['context_id']][(int)$row['id']] = [
					'id' => (int)$row['id'],
					'node_id' => (int)$row['node_id'],
					'node_type' => (int)$row['node_type'],
					'permissions' => (int)$row['permissions'],
				];
			}
			$result->closeCursor();
		}
		return $nodes;
	}

	/**
	 * Groups the rows of the base query by context and formats each context.
	 *
	 * @return Context[]
	 * @throws Exception
	 */
	protected function formatContexts(array $rows, ?string $userId, ?int $nodeType = null, ?int $nodeId = null): array {
		$rowsByContext = [];
		foreach ($rows as $row) {
			$rowsByContext[(int)$row['id']][] = $row;
		}
		$nodes = $this->findNodesForContexts(array_keys($rowsByContext), $nodeType, $nodeId);

		$resultEntities = [];
		foreach ($rowsByContext as $contextId => $contextRows) {
			$resultEntities[] = $this->formatResultRows($contextRows, $userId, $nodes[$contextId] ?? []);
		}
		return $resultEntities;
	}

	/**
	 * @param array<int, array{id: int, node_id: int, node_type: int, permissions: int}> $nodes
	 */
	protected function formatResultRows(array $rows, ?string $userId, array $nodes) {
		$formatted = [
			'id' => $rows[0]['id'],
			'name' => $rows[0]['name'],
			'icon' => $rows[0]['icon'],
			'description' => $rows[0]['description'],
			'owner_id' => $rows[0]['owner_id'],
			'owner_type' => $rows[0]['owner_type'],
		];

		$formatted['sharing'] = array_reduce($rows, function (array $carry, array $item) use ($userId) {
			if ($item['share_id'] === null) {
				// empty Context
				return $carry;
			}
			$carry[$item['share_id']] = [
				'share_id' => (int)$item['share_id'],
				'receiver' => $item['receiver'],
				'receiver_type' => $item['receiver_type'],
				'receiver_display_name' => match ($item['receiver_type']) {
					'user' => $this->userHelper->getUserDisplayName($item['receiver']),
					'group' => $this->groupHelper->getGroupDisplayName($item['receiver']),
					default => $item['receiver'],
				},
				'display_mode_default' => (int)$item['display_mode_default'],
			];
			if ($userId !== null) {
				$item['display_mode'] ??= $item['display_mode_default'];
				$carry[$item['share_id']]['display_mode'] = (int)$item['display_mode'];
			}
			return $carry;
		}, []);

		$formatted['nodes'] = $nodes;

		$formatted['pages'] = array_reduce($rows, function (array $carry, array $item) {
			if ($item['page_id'] === null) {
				// empty Context
				return $carry;
			}
			$carry[$item['page_id']] ??= ['content' => []];
			$carry[$item['page_id']]['id'] = (int)$item['page_id'];
			$carry[$item['page_id']]['page_type'] = $item['page_type'];
			$carry[$item['page_id']]['title'] = $item['page_title'];
			$carry[$item['page_id']]['technical_name'] = $item['page_technical_name'];
			if ($item['content_id'] !== null) {
				$carry[$item['page_id']]['content'][$item['content_id']] = [
					'id' => (int)$item['content_id'],
					'order' => (int)$item['order'],
					'node_rel_id' => $item['content_node_rel_id'] === null ? null : (int)$item['content_node_rel_id'],
					'type' => $item['content_type'],
					'configuration' => $item['content_configuration'],
					'grid_x' => $item['grid_x'] === null ? null : (int)$item['grid_x'],
					'grid_y' => $item['grid_y'] === null ? null : (int)$item['grid_y'],
					'grid_width' => $item['grid_width'] === null ? null : (int)$item['grid_width'],
					'grid_height' => $item['grid_height'] === null ? null : (int)$item['grid_height'],
				];
			}

			return $carry;
		}, []);

		return $this->mapRowToEntity($formatted);
	}

	/**
	 * @return Context[]
	 * @throws Exception
	 */
	public function findAll(?string $userId = null): array {
		$qb = $this->getFindContextBaseQuery($userId);

		$result = $qb->executeQuery();
		$r = $result->fetchAllAssociative();

		return $this->formatContexts($r, $userId);
	}
	public function findForNavBar(string $userId): array {
		$qb = $this->getFindContextBaseQuery($userId);
		$groupIDs = $this->userHelper->getGroupIdsForUser($userId);
		$qb->andWhere($qb->expr()->orX(
			// default
			$qb->expr()->andX(
				// requires lack of user overwrite, indicated by n2.display_mode
				$qb->expr()->isNull('n2.display_mode'),
				// requires a display mode also depending on the role…
				$qb->expr()->orX(
					// not an owner: requires (RECIPIENT or) ALL
					$qb->expr()->andX(
						// groups are not considered, yet
						$qb->expr()->neq('c.owner_id', $qb->createNamedParameter($userId)),
						$qb->expr()->gt('n.display_mode', $qb->createNamedParameter(Application::NAV_ENTRY_MODE_HIDDEN, IQueryBuilder::PARAM_INT)),
					),
					$qb->expr()->andX(
						$qb->expr()->eq('s.receiver_type', $qb->createNamedParameter('group')),
						$qb->expr()->in('s.receiver', $qb->createNamedParameter($groupIDs, IQueryBuilder::PARAM_STR_ARRAY)),
						$qb->expr()->gt('n.display_mode', $qb->createNamedParameter(Application::NAV_ENTRY_MODE_HIDDEN, IQueryBuilder::PARAM_INT)),
					)
				),
			),
			// user override
			$qb->expr()->gt('n2.display_mode', $qb->createNamedParameter(Application::NAV_ENTRY_MODE_HIDDEN, IQueryBuilder::PARAM_INT)),
		));

		$result = $qb->executeQuery();
		$r = $result->fetchAllAssociative();

		return $this->formatContexts($r, $userId);
	}

	/**
	 * @throws Exception
	 * @throws NotFoundError
	 */
	public function findById(int $contextId, ?string $userId = null): Context {
		$qb = $this->getFindContextBaseQuery($userId);
		$qb->andWhere($qb->expr()->eq('c.id', $qb->createNamedParameter($contextId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$r = $result->fetchAllAssociative();

		if (empty($r)) {
			throw new NotFoundError('Context does not exist');
		}

		return $this->formatContexts($r, $userId)[0];
	}

	/**
	 * @return Context[]
	 * @throws Exception
	 */
	public function findAllContainingNode(int $nodeType, int $nodeId, string $userId): array {
		$qb = $this->getFindContextBaseQuery($userId);

		$containing = $this->db->getQueryBuilder();
		$containing->select('context_id')
			->from('tables_contexts_rel_context_node')
			->where($containing->expr()->eq('node_id', $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT)))
			->andWhere($containing->expr()->eq('node_type', $qb->createNamedParameter($nodeType, IQueryBuilder::PARAM_INT)));
		$qb->andWhere($qb->expr()->in('c.id', $qb->createFunction($containing->getSQL())));

		$result = $qb->executeQuery();
		$r = $result->fetchAllAssociative();

		return $this->formatContexts($r, $userId, $nodeType, $nodeId);
	}

	/**
	 * Fetch a map of id → name for the given context IDs.
	 *
	 * @param int[] $ids
	 * @return array<int, string>
	 * @throws Exception
	 */
	public function findIdToNameMap(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name')
			->from($this->table)
			->where($qb->expr()->in('id', $qb->createParameter('ids')));
		$map = [];
		foreach (array_chunk($ids, self::DB_CHUNK_SIZE) as $chunk) {
			$qb->setParameter('ids', $chunk, IQueryBuilder::PARAM_INT_ARRAY);
			$result = $qb->executeQuery();
			foreach ($result->fetchAllAssociative() as $row) {
				$map[(int)$row['id']] = (string)$row['name'];
			}
			$result->closeCursor();
		}
		return $map;
	}

	protected function applyOwnedOrSharedQuery(IQueryBuilder $qb, string $userId): void {
		$sharedToConditions = $qb->expr()->orX();

		// shared by user clause
		$userInitiatedShare = $qb->expr()->eq('s.sender', $qb->createNamedParameter($userId));
		$sharedToConditions->add($userInitiatedShare);

		// shared to user clause
		$userShare = $qb->expr()->andX(
			$qb->expr()->eq('s.receiver_type', $qb->createNamedParameter('user')),
			$qb->expr()->eq('s.receiver', $qb->createNamedParameter($userId)),
		);
		$sharedToConditions->add($userShare);

		// shared to group clause
		$groupIDs = $this->userHelper->getGroupIdsForUser($userId);
		if (!empty($groupIDs)) {
			$groupShares = $qb->expr()->andX(
				$qb->expr()->eq('s.receiver_type', $qb->createNamedParameter('group')),
				$qb->expr()->in('s.receiver', $qb->createNamedParameter($groupIDs, IQueryBuilder::PARAM_STR_ARRAY)),
			);
			$sharedToConditions->add($groupShares);
		}

		// owned contexts + apply share conditions
		$qb->leftJoin('c', 'tables_shares', 's', $qb->expr()->andX(
			$qb->expr()->eq('c.id', 's.node_id'),
			$qb->expr()->eq('s.node_type', $qb->createNamedParameter('context')),
			$sharedToConditions,
		));

		$whereExpression = $qb->expr()->orX(
			$qb->expr()->eq('owner_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)),
			$qb->expr()->isNotNull('s.receiver'),
		);
		if ($qb->getQueryPart('where') === null) {
			$qb->where($whereExpression);
		} else {
			$qb->andWhere($whereExpression);
		}
	}
}
