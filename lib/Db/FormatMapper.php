<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\IDBConnection;

/** @template-extends QBMapper<Format> */
class FormatMapper extends QBMapper {
	protected string $table = 'tables_formats';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, $this->table, Format::class);
	}

	/**
	 * @return Format[]
	 * @throws Exception
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->table)
			->orderBy('technical_name', 'ASC');

		return $this->findEntities($qb);
	}
}
