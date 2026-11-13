<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Migration;

use Closure;
use OCA\Tables\Service\ContextService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * Adds a slug to applications and to the tables and views in them, and
 * fills it in for the existing ones. Version2500Date20261026000001 makes both
 * columns NOT NULL and unique once every row has a name.
 */
class Version2500Date20261026000000 extends SimpleMigrationStep {
	private const CONTEXT_TABLE = 'tables_contexts_context';
	private const NODE_REL_TABLE = 'tables_contexts_rel_context_node';

	public function __construct(
		private readonly IDBConnection $connection,
	) {
	}

	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;
		foreach ([self::CONTEXT_TABLE, self::NODE_REL_TABLE] as $tableName) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$table = $schema->getTable($tableName);
			if (!$table->hasColumn('slug')) {
				$table->addColumn('slug', Types::STRING, [
					'notnull' => false,
					'length' => 200,
				]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}

	#[Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$contextCount = $this->backfillContexts();
		$nodeCount = $this->backfillNodeRelations();

		$output->info('Version2500Date20261026000000: backfilled slug for ' . $contextCount . ' applications and ' . $nodeCount . ' application nodes.');
	}

	private function backfillContexts(): int {
		$select = $this->connection->getQueryBuilder();
		$select->select('id')
			->from(self::CONTEXT_TABLE)
			->where($select->expr()->isNull('slug'));
		$result = $select->executeQuery();
		$contextIds = [];
		while ($row = $result->fetchAssociative()) {
			$contextIds[] = (int)$row['id'];
		}
		$result->closeCursor();

		$update = $this->connection->getQueryBuilder();
		$update->update(self::CONTEXT_TABLE)
			->set('slug', $update->createParameter('slug'))
			->where($update->expr()->eq('id', $update->createParameter('id')));

		// the id is unique, so application-{id} is free for every owner
		$count = 0;
		foreach ($contextIds as $contextId) {
			$update->setParameter('slug', 'application-' . $contextId);
			$update->setParameter('id', $contextId, IQueryBuilder::PARAM_INT);
			$count += $update->executeStatement();
		}
		return $count;
	}

	private function backfillNodeRelations(): int {
		$select = $this->connection->getQueryBuilder();
		$select->select('id', 'context_id', 'node_id', 'node_type', 'slug')
			->from(self::NODE_REL_TABLE)
			->orderBy('context_id')
			->addOrderBy('id');
		$result = $select->executeQuery();
		$rows = $result->fetchAllAssociative();
		$result->closeCursor();

		$names = self::backfillNodeNames($rows);

		$update = $this->connection->getQueryBuilder();
		$update->update(self::NODE_REL_TABLE)
			->set('slug', $update->createParameter('slug'))
			->where($update->expr()->eq('id', $update->createParameter('id')));

		$count = 0;
		foreach ($names as $nodeRelId => $slug) {
			$update->setParameter('slug', $slug);
			$update->setParameter('id', $nodeRelId, IQueryBuilder::PARAM_INT);
			$count += $update->executeStatement();
		}
		return $count;
	}

	/**
	 * The names for the relations that have none: table_{node_id} or view_{node_id},
	 * with a suffix when the application already uses that name. The same node can be
	 * in an application twice, so the suffix is needed even without renamed nodes.
	 *
	 * @param list<array{id: int|string, context_id: int|string, node_id: int|string, node_type: int|string, slug: ?string}> $rows
	 * @return array<int, string> the new name by relation id
	 */
	public static function backfillNodeNames(array $rows): array {
		$takenByContext = [];
		foreach ($rows as $row) {
			if ($row['slug'] !== null && $row['slug'] !== '') {
				$takenByContext[(int)$row['context_id']][$row['slug']] = true;
			}
		}

		$names = [];
		foreach ($rows as $row) {
			if ($row['slug'] !== null && $row['slug'] !== '') {
				continue;
			}
			$contextId = (int)$row['context_id'];
			$slug = ContextService::uniqueSlug(
				ContextService::defaultNodeSlug((int)$row['node_type'], (int)$row['node_id']),
				$takenByContext[$contextId] ?? [],
			);
			$takenByContext[$contextId][$slug] = true;
			$names[(int)$row['id']] = $slug;
		}
		return $names;
	}
}
