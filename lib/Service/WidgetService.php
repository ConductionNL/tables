<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use OCA\Tables\Db\Widget;
use OCA\Tables\Db\WidgetMapper;
use OCA\Tables\Db\WidgetSettingMapper;
use OCA\Tables\Db\WidgetSourceMapper;
use OCA\Tables\ResponseDefinitions;
use OCP\DB\Exception;

/**
 * Stores the widgets of grid pages together with their settings and the node a
 * data widget shows. Callers check permissions and run writes in a transaction.
 *
 * @psalm-import-type TablesWidget from ResponseDefinitions
 * @psalm-import-type TablesWidgetValue from ResponseDefinitions
 */
class WidgetService {
	public function __construct(
		private readonly WidgetMapper $widgetMapper,
		private readonly WidgetSettingMapper $widgetSettingMapper,
		private readonly WidgetSourceMapper $widgetSourceMapper,
	) {
	}

	/**
	 * @param int[] $pageIds
	 * @return array<int, list<TablesWidget>> widgets by page id, in grid order
	 * @throws Exception
	 */
	public function findByPageIds(array $pageIds): array {
		$widgets = $this->widgetMapper->findByPageIds($pageIds);
		$widgetIds = array_map(static fn (Widget $widget): int => $widget->getId(), $widgets);
		$settings = $this->widgetSettingMapper->findSettingsByWidgetIds($widgetIds);
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
				'settings' => $settings[$id] ?? [],
				'source' => isset($sources[$id]) ? ['nodeRelId' => $sources[$id]] : null,
			];
		}
		return $byPage;
	}

	/**
	 * @param array{gridX: int, gridY: int, gridWidth: int, gridHeight: int} $position
	 * @param array<string, TablesWidgetValue> $settings
	 * @throws Exception
	 * @throws \JsonException
	 */
	public function insert(int $pageId, string $type, ?string $title, array $position, array $settings, ?int $nodeRelId): Widget {
		$widget = new Widget();
		$widget->setPageId($pageId);
		$this->applyFields($widget, $type, $title, $position);
		$widget = $this->widgetMapper->insert($widget);

		$this->widgetSettingMapper->replaceSettings($widget->getId(), $settings);
		$this->widgetSourceMapper->setSource($widget->getId(), $nodeRelId);
		return $widget;
	}

	/**
	 * @return int[]
	 * @throws Exception
	 */
	public function findIdsByPageId(int $pageId): array {
		return $this->widgetMapper->findIdsByPageId($pageId);
	}

	/**
	 * Makes the widgets of a page match the given list: widgets with an id are updated,
	 * widgets without one are inserted and widgets of the page that are not listed are
	 * deleted. The ids must belong to the page.
	 *
	 * @param list<array{id: ?int, type: string, title: ?string, position: array{gridX: int, gridY: int, gridWidth: int, gridHeight: int}, settings: array<string, TablesWidgetValue>, nodeRelId: ?int}> $widgets
	 * @throws Exception
	 * @throws \JsonException
	 */
	public function syncPage(int $pageId, array $widgets): void {
		$listedIds = array_filter(array_column($widgets, 'id'), static fn (?int $id): bool => $id !== null);
		$this->deleteByIds(array_values(array_diff($this->widgetMapper->findIdsByPageId($pageId), $listedIds)));

		foreach ($widgets as $data) {
			if ($data['id'] === null) {
				$this->insert($pageId, $data['type'], $data['title'], $data['position'], $data['settings'], $data['nodeRelId']);
				continue;
			}
			$widget = new Widget();
			$widget->setId($data['id']);
			$this->applyFields($widget, $data['type'], $data['title'], $data['position']);
			$this->widgetMapper->update($widget);
			$this->widgetSettingMapper->replaceSettings($data['id'], $data['settings']);
			$this->widgetSourceMapper->setSource($data['id'], $data['nodeRelId']);
		}
	}

	/**
	 * Deletes the widgets of the given pages with their settings and sources.
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
		$this->widgetSettingMapper->deleteByWidgetIds($widgetIds);
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
	 */
	private function applyFields(Widget $widget, string $type, ?string $title, array $position): void {
		$widget->setType($type);
		$widget->setTitle($title);
		$widget->setGridX($position['gridX']);
		$widget->setGridY($position['gridY']);
		$widget->setGridWidth($position['gridWidth']);
		$widget->setGridHeight($position['gridHeight']);
	}
}
