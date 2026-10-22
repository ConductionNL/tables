<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * Widget settings move from one row per setting in tables_contexts_widget_setting to
 * one JSON object in tables_contexts_widget.settings. This step adds the column and
 * copies the rows; Version2400Date20261022000002 drops the old table afterwards.
 * Both do nothing on an instance that never had the settings table.
 */
class Version2400Date20261022000001 extends SimpleMigrationStep {
	public const WIDGET_TABLE = 'tables_contexts_widget';
	public const SETTING_TABLE = 'tables_contexts_widget_setting';

	public function __construct(
		private readonly IDBConnection $connection,
	) {
	}

	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(self::WIDGET_TABLE)) {
			return null;
		}
		$table = $schema->getTable(self::WIDGET_TABLE);
		if ($table->hasColumn('settings')) {
			return null;
		}
		$table->addColumn('settings', Types::TEXT, ['notnull' => false]);
		return $schema;
	}

	#[Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->connection->tableExists(self::SETTING_TABLE)) {
			return;
		}
		$count = $this->copySettings();
		$output->info('Version2400Date20261022000001: moved the settings of ' . $count . ' widgets into their widget row.');
	}

	/**
	 * Builds one JSON object per widget from its setting rows, whose values are each
	 * JSON encoded, and stores it on widgets that have no settings yet.
	 *
	 * @return int the number of widgets written
	 * @throws \JsonException
	 */
	public function copySettings(): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('widget_id', 'name', 'value')
			->from(self::SETTING_TABLE)
			->orderBy('widget_id', 'ASC')
			->addOrderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$byWidget = [];
		while ($row = $result->fetch()) {
			$byWidget[(int)$row['widget_id']][(string)$row['name']] = $row['value'] === null
				? null
				: json_decode((string)$row['value'], true);
		}
		$result->closeCursor();

		$update = $this->connection->getQueryBuilder();
		$update->update(self::WIDGET_TABLE)
			->set('settings', $update->createParameter('settings'))
			->where($update->expr()->eq('id', $update->createParameter('id')))
			->andWhere($update->expr()->isNull('settings'));

		$count = 0;
		foreach ($byWidget as $widgetId => $settings) {
			$update->setParameter('settings', json_encode($settings, JSON_THROW_ON_ERROR));
			$update->setParameter('id', $widgetId, IQueryBuilder::PARAM_INT);
			$count += $update->executeStatement();
		}
		return $count;
	}
}
