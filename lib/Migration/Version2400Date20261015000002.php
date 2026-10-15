<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Migration;

use Closure;
use OCA\Tables\Db\Page;
use OCA\Tables\Helper\TechnicalNameHelper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * Gives every application page a technical name that is unique within its
 * application: start pages get `dashboard`, other pages a name made from their
 * title, else `page_<id>`. Valid names that are free keep their value.
 */
class Version2400Date20261015000002 extends SimpleMigrationStep {
	private const TABLE = 'tables_contexts_page';

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$select->select('id', 'context_id', 'page_type', 'title', 'technical_name')
			->from(self::TABLE)
			->orderBy('context_id')
			->addOrderBy('id');
		$result = $select->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$names = self::backfillNames($rows);
		if ($names === []) {
			return;
		}

		$update = $this->db->getQueryBuilder();
		$update->update(self::TABLE)
			->set('technical_name', $update->createParameter('technicalName'))
			->where($update->expr()->eq('id', $update->createParameter('pageId')));

		$this->db->beginTransaction();
		try {
			foreach ($names as $pageId => $technicalName) {
				$update->setParameter('technicalName', $technicalName);
				$update->setParameter('pageId', $pageId, IQueryBuilder::PARAM_INT);
				$update->executeStatement();
			}
			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * The new technical name of every page row that needs one, by page id.
	 *
	 * @param list<array<string, mixed>> $rows page rows ordered by id
	 * @return array<int, string>
	 */
	public static function backfillNames(array $rows): array {
		$pagesByContext = [];
		foreach ($rows as $row) {
			$pageId = (int)$row['id'];
			$title = is_string($row['title'] ?? null) ? $row['title'] : '';
			$isStartPage = $row['page_type'] === Page::TYPE_STARTPAGE;
			$fallback = $isStartPage || preg_match('/[a-z0-9]/i', $title) === 1
				? TechnicalNameHelper::pageFallback($isStartPage, $title)
				: 'page_' . $pageId;
			$pagesByContext[(int)$row['context_id']][$pageId] = [
				'name' => is_string($row['technical_name'] ?? null) ? $row['technical_name'] : null,
				'fallback' => $fallback,
			];
		}

		$changes = [];
		foreach ($pagesByContext as $pages) {
			foreach (TechnicalNameHelper::assign($pages) as $pageId => $technicalName) {
				if ($pages[$pageId]['name'] !== $technicalName) {
					$changes[$pageId] = $technicalName;
				}
			}
		}
		return $changes;
	}
}
