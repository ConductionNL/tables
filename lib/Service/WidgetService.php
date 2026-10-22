<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use OCA\Tables\Db\Widget;
use OCA\Tables\Db\WidgetMapper;
use OCA\Tables\Db\WidgetSourceMapper;
use OCP\DB\Exception;

/**
 * Stores the widgets of grid pages, settings included, and the node a data widget
 * shows. Callers check permissions and run writes in a transaction.
 *
 * @psalm-import-type TablesWidgetSettingValue from Widget
 * @psalm-type TablesWidgetRecord = array{id: int, type: string, title: ?string, gridX: int, gridY: int, gridWidth: int, gridHeight: int, settings: array<string, TablesWidgetSettingValue>, source: ?array{nodeRelId: int}}
 */
class WidgetService {
	public function __construct(
		private readonly WidgetMapper $widgetMapper,
		private readonly WidgetSourceMapper $widgetSourceMapper,
	) {
	}

	/**
	 * @param int[] $pageIds
	 * @return array<int, list<TablesWidgetRecord>> widgets by page id, in grid order
	 * @throws Exception
	 */
	public function findByPageIds(array $pageIds): array {
		$widgets = $this->widgetMapper->findByPageIds($pageIds);
		$widgetIds = array_map(static fn (Widget $widget): int => $widget->getId(), $widgets);
		$sources = $this->widgetSourceMapper->findNodeRelIdsByWidgetIds($widgetIds);

		$byPage = [];
		foreach ($widgets as $widget) {
			$id = $widget->getId();
			$byPage[$widget->getPageId()][] = [
				'id' => $id,
				'type' => $widget->getType(),
				'title' => $widget->getTitle(),
				'gridX' => $widget->getGridX(),
				'gridY' => $widget->getGridY(),
				'gridWidth' => $widget->getGridWidth(),
				'gridHeight' => $widget->getGridHeight(),
				'settings' => $widget->getSettingsArray(),
				'source' => isset($sources[$id]) ? ['nodeRelId' => $sources[$id]] : null,
			];
		}
		return $byPage;
	}

	/**
	 * @param array{gridX: int, gridY: int, gridWidth: int, gridHeight: int} $position
	 * @param array<string, TablesWidgetSettingValue> $settings
	 * @throws Exception
	 * @throws \JsonException
	 */
	public function insert(int $pageId, string $type, ?string $title, array $position, array $settings, ?int $nodeRelId): Widget {
		$widget = new Widget();
		$widget->setPageId($pageId);
		$this->applyFields($widget, $type, $title, $position, $settings);
		$widget = $this->widgetMapper->insert($widget);

		$this->widgetSourceMapper->setSource($widget->getId(), $nodeRelId);
		return $widget;
	}

	/**
	 * Deletes the widgets of the given pages with their sources.
	 *
	 * @param int[] $pageIds
	 * @throws Exception
	 */
	public function deleteByPageIds(array $pageIds): void {
		$widgetIds = [];
		foreach ($pageIds as $pageId) {
			$widgetIds = array_merge($widgetIds, $this->widgetMapper->findIdsByPageId($pageId));
		}
		$this->deleteByIds($widgetIds);
	}

	/**
	 * @param int[] $widgetIds
	 * @throws Exception
	 */
	public function deleteByIds(array $widgetIds): void {
		$this->widgetSourceMapper->deleteByWidgetIds($widgetIds);
		$this->widgetMapper->deleteByIds($widgetIds);
	}

	/**
	 * Unlinks every widget that shows one of the given node relations. The widgets stay.
	 *
	 * @param int[] $nodeRelIds
	 * @throws Exception
	 */
	public function deleteSourcesByNodeRelIds(array $nodeRelIds): void {
		$this->widgetSourceMapper->deleteByNodeRelIds($nodeRelIds);
	}

	/**
	 * @param array{gridX: int, gridY: int, gridWidth: int, gridHeight: int} $position
	 * @param array<string, TablesWidgetSettingValue> $settings
	 * @throws \JsonException
	 */
	private function applyFields(Widget $widget, string $type, ?string $title, array $position, array $settings): void {
		$widget->setType($type);
		$widget->setTitle($title);
		$widget->setGridX($position['gridX']);
		$widget->setGridY($position['gridY']);
		$widget->setGridWidth($position['gridWidth']);
		$widget->setGridHeight($position['gridHeight']);
		$widget->setSettingsArray($settings);
	}
}
