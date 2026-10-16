<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Db;

use OCA\Tables\ResponseDefinitions;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and writes widget settings as name => value maps. Values are stored JSON
 * encoded, one row per setting.
 *
 * @psalm-import-type TablesWidgetValue from ResponseDefinitions
 * @template-extends QBMapper<WidgetSetting>
 */
class WidgetSettingMapper extends QBMapper {
	protected string $table = 'tables_contexts_widget_setting';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, $this->table, WidgetSetting::class);
	}

	/**
	 * @param int[] $widgetIds
	 * @return array<int, array<string, TablesWidgetValue>> settings by widget id
	 * @throws Exception
	 */
	public function findSettingsByWidgetIds(array $widgetIds): array {
		if ($widgetIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('widget_id', 'name', 'value')
			->from($this->table)
			->where($qb->expr()->in('widget_id', $qb->createNamedParameter($widgetIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$settings = [];
		while ($row = $result->fetchAssociative()) {
			$settings[(int)$row['widget_id']][(string)$row['name']] = $row['value'] === null
				? null
				: json_decode((string)$row['value'], true);
		}
		$result->closeCursor();
		return $settings;
	}

	/**
	 * Replaces all settings of a widget.
	 *
	 * @param array<string, TablesWidgetValue> $settings
	 * @throws Exception
	 * @throws \JsonException
	 */
	public function replaceSettings(int $widgetId, array $settings): void {
		$this->deleteByWidgetIds([$widgetId]);
		foreach ($settings as $name => $value) {
			$setting = new WidgetSetting();
			$setting->setWidgetId($widgetId);
			$setting->setName((string)$name);
			$setting->setValue($value === null ? null : json_encode($value, JSON_THROW_ON_ERROR));
			$this->insert($setting);
		}
	}

	/**
	 * @param int[] $widgetIds
	 * @throws Exception
	 */
	public function deleteByWidgetIds(array $widgetIds): void {
		if ($widgetIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->table)
			->where($qb->expr()->in('widget_id', $qb->createNamedParameter($widgetIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}
}
