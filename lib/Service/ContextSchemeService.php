<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use InvalidArgumentException;
use OCA\Tables\AppInfo\Application;
use OCA\Tables\Db\Context;
use OCA\Tables\Db\MenuItem;
use OCA\Tables\Db\Page;
use OCA\Tables\Db\TableMapper;
use OCA\Tables\Db\ViewMapper;
use OCA\Tables\Helper\TechnicalNameHelper;
use OCA\Tables\Model\MenuItemInput;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

/**
 * The menu of an application in a scheme, and the grid views of schemes of an older version.
 * Menu items name tables and views by uuid and pages by technical name; an import maps them to
 * what exists here. Grid views become scheme pages, which the page import of ContextService takes.
 */
class ContextSchemeService {
	private const PAGE_TITLE_MAX_LENGTH = 200;

	public function __construct(
		private readonly TableMapper $tableMapper,
		private readonly ViewMapper $viewMapper,
		private readonly PermissionsService $permissionsService,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Menu items with their targets as uuids, or as the technical name of a page of the
	 * application. An item whose target is gone is left out.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function exportMenuItems(Context $context): array {
		$menuItems = [];
		$pages = $context->getPages() ?? [];
		$technicalNamesById = array_column($context->getMenuItems() ?? [], 'technicalName', 'id');
		foreach ($context->getMenuItems() ?? [] as $item) {
			if ($item['broken'] ?? false) {
				continue;
			}
			$exported = [
				'label' => $item['label'],
				'icon' => $item['icon'],
				'targetType' => $item['targetType'],
				'targetUuid' => null,
				'targetPageTechnicalName' => null,
				'url' => $item['url'],
				'technicalName' => $item['technicalName'],
				'parentTechnicalName' => $item['parentId'] !== null ? ($technicalNamesById[$item['parentId']] ?? null) : null,
			];
			try {
				match ($item['targetType']) {
					MenuItem::TARGET_TABLE => $exported['targetUuid'] = $this->tableMapper->find((int)$item['targetId'])->getUuid(),
					MenuItem::TARGET_VIEW => $exported['targetUuid'] = $this->viewMapper->find((int)$item['targetId'])->getUuid(),
					MenuItem::TARGET_PAGE => $exported['targetPageTechnicalName'] = $pages[$item['targetId']]['technical_name'] ?? throw new DoesNotExistException('Page ' . $item['targetId'] . ' is not part of the application'),
					default => null,
				};
			} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
				$this->logger->warning('Menu item target could not be exported, the item is skipped', ['exception' => $e]);
				continue;
			}
			$menuItems[] = $exported;
		}
		return $menuItems;
	}

	/**
	 * The grid views of a scheme of an older version as scheme pages. Each page is named after
	 * the grid view's technical name, else its title, apart from the pages the scheme already
	 * names. Its widgets keep their place; a data widget names its table or view by uuid, which
	 * becomes its source: a node of the scheme with that uuid, else a node to add when the importer
	 * can manage it. A data widget without either stays without a source and asks for a new table.
	 *
	 * @param list<array<string, mixed>> $gridViews
	 * @param list<array<string, mixed>> $schemeNodes the nodes of the scheme, with their exported relation id
	 * @param array<string, true> $reserved technical names the pages of the scheme use
	 * @return array{pages: list<array<string, mixed>>, nodes: list<array{id: int, node_type: int, node_uuid: string, permissions: int}>, technicalNames: array<string, string>} the pages, the nodes to add, and the technical name of the page of every grid view by its uuid
	 */
	public function pagesFromGridViews(array $gridViews, array $schemeNodes, array $reserved, string $userId): array {
		$names = [];
		foreach ($gridViews as $key => $gridView) {
			$technicalName = $gridView['technicalName'] ?? $gridView['technical_name'] ?? null;
			$names[$key] = [
				'name' => is_string($technicalName) ? $technicalName : null,
				'fallback' => TechnicalNameHelper::pageFallback(false, $gridView['title']),
			];
		}
		$names = TechnicalNameHelper::assign($names, $reserved);

		$nodeIdsByUuid = [];
		foreach ($schemeNodes as $node) {
			if (isset($node['id']) && is_string($node['node_uuid'] ?? null)) {
				$nodeIdsByUuid[(int)$node['node_type'] . ':' . $node['node_uuid']] = (int)$node['id'];
			}
		}
		$addedNodes = [];
		$pages = [];
		$technicalNames = [];
		foreach ($gridViews as $key => $gridView) {
			$widgets = [];
			foreach ($this->gridViewWidgets($gridView['grid'] ?? null) as $widget) {
				if (array_key_exists('target', $widget)) {
					$target = $widget['target'];
					unset($widget['target']);
					$nodeRelId = $target === null ? null : $this->gridViewSourceNode($target, $nodeIdsByUuid, $addedNodes, $userId);
					$widget['source'] = $nodeRelId === null ? null : ['nodeRelId' => $nodeRelId];
				}
				$widgets[] = $widget;
			}
			$pages[] = [
				'page_type' => Page::TYPE_GRID,
				'title' => mb_substr(trim($gridView['title']), 0, self::PAGE_TITLE_MAX_LENGTH),
				'emoji' => is_string($gridView['emoji'] ?? null) && $gridView['emoji'] !== '' ? $gridView['emoji'] : null,
				'description' => is_string($gridView['description'] ?? null) && $gridView['description'] !== '' ? $gridView['description'] : null,
				'technical_name' => $names[$key],
				'widgets' => $widgets,
			];
			$technicalNames[$gridView['uuid']] = $names[$key];
		}
		return ['pages' => $pages, 'nodes' => array_values($addedNodes), 'technicalNames' => $technicalNames];
	}

	/**
	 * Menu items of a scheme with their targets turned into local ids. Items that are invalid, or
	 * whose target does not exist here or cannot be managed by the importer, are skipped. An item
	 * of an older scheme that opened a grid view opens the page that grid view became. A child
	 * names its parent by technical name; when the parent was skipped, the child moves to the top.
	 * A technical name that an earlier item already uses is dropped, so the item gets a derived one.
	 *
	 * @param list<mixed> $menuItems
	 * @param array<string, int> $pageIds local page ids by technical name
	 * @param array<string, string> $gridViewPages technical names of the pages that grid views became, by grid view uuid
	 * @return list<MenuItemInput>
	 */
	public function resolveMenuItems(array $menuItems, string $userId, array $pageIds, array $gridViewPages): array {
		$prepared = [];
		$usedTechnicalNames = [];
		foreach ($menuItems as $item) {
			if (!is_array($item)) {
				continue;
			}
			$targetType = $item['targetType'] ?? MenuItem::TARGET_URL;
			$targetUuid = is_string($item['targetUuid'] ?? null) ? $item['targetUuid'] : null;
			if ($targetType === MenuItem::TARGET_VIEW && $targetUuid !== null && isset($gridViewPages[$targetUuid])) {
				$item['targetType'] = MenuItem::TARGET_PAGE;
				$item['targetId'] = $pageIds[$gridViewPages[$targetUuid]] ?? null;
			} elseif ($targetType === MenuItem::TARGET_PAGE) {
				$pageName = $item['targetPageTechnicalName'] ?? null;
				$item['targetId'] = is_string($pageName) ? ($pageIds[$pageName] ?? null) : null;
			} elseif ($targetType !== MenuItem::TARGET_URL) {
				$item['targetId'] = is_string($targetType) ? $this->resolveTargetId($targetType, $targetUuid, $userId) : null;
			}
			$parentTechnicalName = is_string($item['parentTechnicalName'] ?? null) ? $item['parentTechnicalName'] : null;
			unset($item['targetUuid'], $item['targetPageTechnicalName'], $item['parentTechnicalName'], $item['parentIndex']);
			try {
				MenuItemInput::createFromInputArray($item);
			} catch (InvalidArgumentException $e) {
				$this->logger->info('Menu item skipped on import: {reason}', ['reason' => $e->getMessage()]);
				continue;
			}
			$technicalName = $item['technicalName'] ?? null;
			if (is_string($technicalName) && isset($usedTechnicalNames[$technicalName])) {
				unset($item['technicalName']);
			} elseif (is_string($technicalName)) {
				$usedTechnicalNames[$technicalName] = true;
			}
			$prepared[] = ['item' => $item, 'parent' => $parentTechnicalName];
		}

		$parentPositions = [];
		foreach ($prepared as $position => $entry) {
			$technicalName = $entry['item']['technicalName'] ?? null;
			if ($entry['parent'] === null && is_string($technicalName) && !isset($parentPositions[$technicalName])) {
				$parentPositions[$technicalName] = $position;
			}
		}
		$items = [];
		foreach ($prepared as $entry) {
			$entry['item']['parentIndex'] = $entry['parent'] === null ? null : ($parentPositions[$entry['parent']] ?? null);
			$items[] = $entry['item'];
		}
		return MenuItemInput::createListFromInputArray($items);
	}

	/**
	 * The widgets of a grid view, in the form of an exported page widget, placed as its layout
	 * placed them. A data widget carries its table or view as `target` ({type, uuid} or null).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function gridViewWidgets(mixed $grid): array {
		$layout = [];
		foreach (is_array($grid) && is_array($grid['layout'] ?? null) ? $grid['layout'] : [] as $item) {
			if (is_array($item) && isset($item['widgetId'])) {
				$layout[(string)$item['widgetId']] = $item;
			}
		}
		$widgets = [];
		foreach (is_array($grid) && is_array($grid['widgets'] ?? null) ? $grid['widgets'] : [] as $gridWidget) {
			$position = is_array($gridWidget) ? ($layout[(string)($gridWidget['id'] ?? '')] ?? null) : null;
			if ($position === null || !is_string($gridWidget['type'] ?? null)) {
				continue;
			}
			$configuration = is_array($gridWidget['configuration'] ?? null) ? $gridWidget['configuration'] : [];
			$content = is_array($gridWidget['content'] ?? null) ? $gridWidget['content'] : [];
			$widget = [
				'type' => $gridWidget['type'],
				'title' => $this->gridViewWidgetTitle($gridWidget['type'], $configuration),
				'gridX' => (int)($position['gridX'] ?? 0),
				'gridY' => (int)($position['gridY'] ?? 0),
				'gridWidth' => (int)($position['gridWidth'] ?? 1),
				'gridHeight' => (int)($position['gridHeight'] ?? 1),
			];
			switch ($gridWidget['type']) {
				case GridWidgetTypes::TYPE_HEADER:
					$widget['settings'] = array_filter([
						'heading' => $content['title'] ?? null,
						'subheading' => $content['subtitle'] ?? null,
						'textAlign' => $content['textAlign'] ?? null,
						'backgroundColor' => $content['backgroundColor'] ?? null,
						'textColor' => $content['textColor'] ?? null,
					], static fn (mixed $value): bool => $value !== null);
					break;
				case GridWidgetTypes::TYPE_TEXT:
					$widget['settings'] = array_filter(['text' => $content['text'] ?? null], static fn (mixed $value): bool => $value !== null);
					break;
				case GridWidgetTypes::TYPE_DATA:
					$target = is_array($content['target'] ?? null) ? $content['target'] : [];
					$widget['settings'] = [];
					$widget['target'] = in_array($target['type'] ?? null, [MenuItem::TARGET_TABLE, MenuItem::TARGET_VIEW], true) && is_string($target['uuid'] ?? null)
						? ['type' => $target['type'], 'uuid' => $target['uuid']]
						: null;
					break;
				default:
					$widget['settings'] = $content;
			}
			$widgets[] = $widget;
		}
		return $widgets;
	}

	/**
	 * The title a grid view widget showed: its configured title, when the widget showed one.
	 * Text widgets showed their title unless told not to, the other types only when told to.
	 *
	 * @param array<string, mixed> $configuration
	 */
	private function gridViewWidgetTitle(string $type, array $configuration): ?string {
		$title = is_string($configuration['title'] ?? null) ? trim($configuration['title']) : '';
		$showTitle = $configuration['showTitle'] ?? ($type === GridWidgetTypes::TYPE_TEXT);
		return $title !== '' && $showTitle === true ? $title : null;
	}

	/**
	 * The scheme node a grid view data widget shows: the node of the scheme with the uuid of its
	 * target, else a node added for it when the target exists here and the importer can manage it.
	 *
	 * @param array{type: string, uuid: string} $target
	 * @param array<string, int> $nodeIdsByUuid scheme node ids by node type and uuid
	 * @param array<string, array{id: int, node_type: int, node_uuid: string, permissions: int}> $addedNodes the nodes added so far, by node type and uuid
	 */
	private function gridViewSourceNode(array $target, array &$nodeIdsByUuid, array &$addedNodes, string $userId): ?int {
		$nodeType = $target['type'] === MenuItem::TARGET_TABLE ? Application::NODE_TYPE_TABLE : Application::NODE_TYPE_VIEW;
		$key = $nodeType . ':' . $target['uuid'];
		if (isset($nodeIdsByUuid[$key])) {
			return $nodeIdsByUuid[$key];
		}
		$nodeId = $this->resolveTargetId($target['type'], $target['uuid'], $userId);
		if ($nodeId === null) {
			$this->logger->info('Grid view widget imported without a source, its table or view is not available here');
			return null;
		}
		// Scheme node ids are positive; the added nodes count down from -1 so they never collide
		$id = -1 - count($addedNodes);
		$addedNodes[$key] = ['id' => $id, 'node_type' => $nodeType, 'node_uuid' => $target['uuid'], 'permissions' => Application::PERMISSION_READ];
		$nodeIdsByUuid[$key] = $id;
		return $id;
	}

	/**
	 * The local id of a table or view named by uuid, or null when it does not exist here or the
	 * importer cannot manage it.
	 */
	private function resolveTargetId(string $targetType, ?string $uuid, string $userId): ?int {
		$id = $uuid === null ? null : $this->localId($targetType, $uuid);
		if ($id === null) {
			return null;
		}
		$nodeType = $targetType === MenuItem::TARGET_TABLE ? Application::NODE_TYPE_TABLE : Application::NODE_TYPE_VIEW;
		return $this->permissionsService->canManageNodeById($nodeType, $id, $userId) ? $id : null;
	}

	/**
	 * The id of the table or view with the given uuid here, or null.
	 */
	private function localId(string $targetType, string $uuid): ?int {
		if ($uuid === '') {
			return null;
		}
		try {
			return match ($targetType) {
				MenuItem::TARGET_TABLE => $this->tableMapper->findByUuid($uuid)->getId(),
				MenuItem::TARGET_VIEW => $this->viewMapper->findByUuid($uuid)?->getId(),
				default => null,
			};
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}
}
