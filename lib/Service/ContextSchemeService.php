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
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\Helper\PageContentHelper;
use OCA\Tables\Helper\TechnicalNameHelper;
use OCA\Tables\Model\ContextScheme;
use OCA\Tables\Model\MenuItemInput;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

/**
 * The menu and the pages of an application in a scheme. Menu items name tables and views by
 * uuid and pages by their id in the scheme; data widgets name their node by its relation id in
 * the scheme's nodes. An import maps all of them to what exists here.
 */
class ContextSchemeService {
	private const PAGE_TITLE_MAX_LENGTH = 200;

	public function __construct(
		private readonly PageService $pageService,
		private readonly TableMapper $tableMapper,
		private readonly ViewMapper $viewMapper,
		private readonly PermissionsService $permissionsService,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * The pages of an application with their widgets, as the page API returns them.
	 *
	 * @return list<array<string, mixed>>
	 * @throws NotFoundError|Exception
	 */
	public function exportPages(int $contextId, string $userId): array {
		return $this->pageService->findAll($contextId, $userId);
	}

	/**
	 * Menu items with their targets as uuids, or as the id of a page of the scheme. An item whose
	 * target is gone is left out.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function exportMenuItems(Context $context): array {
		$menuItems = [];
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
				'targetPageId' => null,
				'url' => $item['url'],
				'technicalName' => $item['technicalName'],
				'parentTechnicalName' => $item['parentId'] !== null ? ($technicalNamesById[$item['parentId']] ?? null) : null,
			];
			try {
				match ($item['targetType']) {
					MenuItem::TARGET_TABLE => $exported['targetUuid'] = $this->tableMapper->find((int)$item['targetId'])->getUuid(),
					MenuItem::TARGET_VIEW => $exported['targetUuid'] = $this->viewMapper->find((int)$item['targetId'])->getUuid(),
					MenuItem::TARGET_PAGE => $exported['targetPageId'] = $item['targetId'],
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
	 * Brings the pages of a scheme into an application whose nodes the import already set. The
	 * start page of the scheme fills the start page here, but only when it was arranged on the
	 * grid; otherwise the start page keeps the content the nodes gave it. Every grid page, and every
	 * grid view of an older scheme, gets a technical name: its own, else one made from its title,
	 * unique within the scheme and apart from the start page here. It replaces the page with that
	 * technical name, or is added. A data widget whose table or view does not exist here, or that
	 * the importer cannot add to the application, is left out.
	 *
	 * @return array{pages: array<array-key, int>, gridViews: array<string, int>} the local page ids by page id in the scheme, and by grid view uuid
	 * @throws BadRequestError|NotFoundError|PermissionError|Exception
	 */
	public function importPages(Context $context, ContextScheme $scheme, string $userId): array {
		$contextId = $context->getId();
		$nodeKeys = [];
		foreach ($context->getNodes() ?? [] as $node) {
			$nodeKeys[$this->nodeKey((int)$node['node_type'], (int)$node['node_id'])] = true;
		}
		$schemeNodes = $this->schemeNodes($scheme->getNodes() ?? []);

		$localPages = $this->pageService->findAll($contextId, $userId);
		$startPageId = null;
		$reserved = [];
		$pagesByTechnicalName = [];
		foreach ($localPages as $page) {
			if ($page['page_type'] === Page::TYPE_STARTPAGE) {
				$startPageId = $page['id'];
				$reserved[$page['technical_name']] = true;
			} else {
				$pagesByTechnicalName[$page['technical_name']] = $page['id'];
			}
		}
		$technicalNames = $this->schemeTechnicalNames($scheme, $reserved);

		$pageIds = [];
		foreach ($scheme->getPages() ?? [] as $key => $page) {
			if (!is_array($page)) {
				continue;
			}
			$sourceId = $page['id'] ?? $key;
			$content = PageContentHelper::readContent($this->storedContent($page['content'] ?? []));
			if (($page['page_type'] ?? $page['pageType'] ?? null) === Page::TYPE_STARTPAGE) {
				if ($startPageId === null) {
					continue;
				}
				$pageIds[$sourceId] = $startPageId;
				if ($this->isArrangedOnTheGrid($page['content'] ?? [])) {
					$widgets = $this->widgetsFromContent($content, $schemeNodes, $nodeKeys, $userId);
					$this->pageService->saveWidgets($contextId, $startPageId, $widgets, $userId);
				}
				continue;
			}

			$pageId = $this->importPage($contextId, $page['title'] ?? null, $technicalNames['page:' . $key], $pagesByTechnicalName, $userId);
			$pageIds[$sourceId] = $pageId;
			$widgets = $this->widgetsFromContent($content, $schemeNodes, $nodeKeys, $userId);
			$this->pageService->saveWidgets($contextId, $pageId, $widgets, $userId);
		}

		$gridViewPageIds = [];
		foreach ($scheme->getGridViews() as $key => $gridView) {
			$pageId = $this->importPage($contextId, $gridView['title'], $technicalNames['gridView:' . $key], $pagesByTechnicalName, $userId);
			$widgets = $this->widgetsFromGridView($gridView['grid'] ?? null, $nodeKeys, $userId);
			$this->pageService->saveWidgets($contextId, $pageId, $widgets, $userId);
			$gridViewPageIds[$gridView['uuid']] = $pageId;
		}

		return ['pages' => $pageIds, 'gridViews' => $gridViewPageIds];
	}

	/**
	 * Menu items of a scheme with their targets turned into local ids. Items that are invalid, or
	 * whose target does not exist here or cannot be managed by the importer, are skipped. An item
	 * of an older scheme that opened a grid view opens the page that grid view became. A child
	 * names its parent by technical name; when the parent was skipped, the child moves to the top.
	 * A technical name that an earlier item already uses is dropped, so the item gets a derived one.
	 *
	 * @param list<mixed> $menuItems
	 * @param array<array-key, int> $pageIds local page ids by page id in the scheme
	 * @param array<string, int> $gridViewPageIds local page ids by grid view uuid
	 * @return list<MenuItemInput>
	 */
	public function resolveMenuItems(array $menuItems, string $userId, array $pageIds, array $gridViewPageIds): array {
		$prepared = [];
		$usedTechnicalNames = [];
		foreach ($menuItems as $item) {
			if (!is_array($item)) {
				continue;
			}
			$targetType = $item['targetType'] ?? MenuItem::TARGET_URL;
			$targetUuid = is_string($item['targetUuid'] ?? null) ? $item['targetUuid'] : null;
			if ($targetType === MenuItem::TARGET_VIEW && $targetUuid !== null && isset($gridViewPageIds[$targetUuid])) {
				$item['targetType'] = MenuItem::TARGET_PAGE;
				$item['targetId'] = $gridViewPageIds[$targetUuid];
			} elseif ($targetType === MenuItem::TARGET_PAGE) {
				$targetPageId = $item['targetPageId'] ?? null;
				$item['targetId'] = (is_int($targetPageId) || is_string($targetPageId)) ? ($pageIds[$targetPageId] ?? null) : null;
			} elseif ($targetType !== MenuItem::TARGET_URL) {
				$item['targetId'] = is_string($targetType) ? $this->resolveTargetId($targetType, $targetUuid, $userId) : null;
			}
			$parentTechnicalName = is_string($item['parentTechnicalName'] ?? null) ? $item['parentTechnicalName'] : null;
			unset($item['targetUuid'], $item['targetPageId'], $item['parentTechnicalName'], $item['parentIndex']);
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
	 * The local table or view of every node of the scheme, by its relation id in the scheme.
	 *
	 * @param array<array-key, mixed> $nodes
	 * @return array<int, array{nodeType: int, nodeId: int}>
	 */
	private function schemeNodes(array $nodes): array {
		$resolved = [];
		foreach ($nodes as $node) {
			if (!is_array($node) || !isset($node['id'], $node['node_type']) || !is_string($node['node_uuid'] ?? null)) {
				continue;
			}
			$nodeType = (int)$node['node_type'];
			$nodeId = $this->localId($nodeType === Application::NODE_TYPE_TABLE ? MenuItem::TARGET_TABLE : MenuItem::TARGET_VIEW, $node['node_uuid']);
			if ($nodeId !== null) {
				$resolved[(int)$node['id']] = ['nodeType' => $nodeType, 'nodeId' => $nodeId];
			}
		}
		return $resolved;
	}

	/**
	 * Page content from a scheme in the form it is stored in: an exported page has its
	 * configuration and content apart, the database keeps them together.
	 *
	 * @param mixed $content
	 * @return array<array-key, array<string, mixed>>
	 */
	private function storedContent(mixed $content): array {
		if (!is_array($content)) {
			return [];
		}
		$stored = [];
		foreach ($content as $key => $entry) {
			if (!is_array($entry)) {
				continue;
			}
			if (is_array($entry['configuration'] ?? null) || is_array($entry['content'] ?? null)) {
				$entry['configuration'] = [
					'configuration' => is_array($entry['configuration'] ?? null) ? $entry['configuration'] : [],
					'content' => is_array($entry['content'] ?? null) ? $entry['content'] : [],
				];
				unset($entry['content']);
			}
			$stored[$key] = $entry;
		}
		return $stored;
	}

	/**
	 * Whether page content was arranged on the grid: it has a widget that is not a table or view,
	 * or a position. Content of an older scheme only lists its tables and views in order.
	 */
	private function isArrangedOnTheGrid(mixed $content): bool {
		if (!is_array($content)) {
			return false;
		}
		foreach ($content as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$type = $entry['type'] ?? null;
			if ((is_string($type) && $type !== '' && $type !== GridWidgetTypes::TYPE_DATA)
				|| ($entry['grid_y'] ?? $entry['gridY'] ?? null) !== null) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The widgets to save for page content read from a scheme.
	 *
	 * @param array<array-key, array<string, mixed>> $content read by PageContentHelper
	 * @param array<int, array{nodeType: int, nodeId: int}> $schemeNodes
	 * @param array<string, true> $nodeKeys the nodes of the application
	 * @return list<array<string, mixed>>
	 */
	private function widgetsFromContent(array $content, array $schemeNodes, array &$nodeKeys, string $userId): array {
		$widgets = [];
		foreach ($content as $entry) {
			$widget = [
				'type' => $entry['type'],
				'configuration' => $entry['configuration'],
				'content' => $entry['content'],
				'gridX' => $entry['grid_x'],
				'gridY' => $entry['grid_y'],
				'gridWidth' => $entry['grid_width'],
				'gridHeight' => $entry['grid_height'],
			];
			if ($entry['type'] === GridWidgetTypes::TYPE_DATA) {
				$node = $entry['node_rel_id'] !== null ? ($schemeNodes[$entry['node_rel_id']] ?? null) : null;
				if ($node === null || !$this->canPlace($node, $nodeKeys, $userId)) {
					$this->logger->info('Data widget skipped on import, its table or view does not exist here');
					continue;
				}
				$widget['node'] = $node;
			}
			$widgets[] = $widget;
		}
		return $widgets;
	}

	/**
	 * The widgets of a grid view of an older scheme, placed as its layout placed them. A data
	 * widget named its table or view in its content; on a page that is the widget's node.
	 *
	 * @param array<string, true> $nodeKeys the nodes of the application
	 * @return list<array<string, mixed>>
	 */
	private function widgetsFromGridView(mixed $grid, array &$nodeKeys, string $userId): array {
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
			$content = is_array($gridWidget['content'] ?? null) ? $gridWidget['content'] : [];
			$widget = [
				'type' => $gridWidget['type'],
				'configuration' => is_array($gridWidget['configuration'] ?? null) ? $gridWidget['configuration'] : [],
				'content' => $content,
				'gridX' => $position['gridX'] ?? null,
				'gridY' => $position['gridY'] ?? null,
				'gridWidth' => $position['gridWidth'] ?? null,
				'gridHeight' => $position['gridHeight'] ?? null,
			];
			if ($gridWidget['type'] === GridWidgetTypes::TYPE_DATA) {
				$target = is_array($content['target'] ?? null) ? $content['target'] : [];
				unset($widget['content']['target']);
				$targetType = $target['type'] ?? null;
				$nodeId = is_string($targetType) && is_string($target['uuid'] ?? null) ? $this->localId($targetType, $target['uuid']) : null;
				$node = $nodeId === null ? null : [
					'nodeType' => $targetType === MenuItem::TARGET_TABLE ? Application::NODE_TYPE_TABLE : Application::NODE_TYPE_VIEW,
					'nodeId' => $nodeId,
				];
				if ($node === null || !$this->canPlace($node, $nodeKeys, $userId)) {
					$this->logger->info('Grid widget skipped on import, its table or view does not exist here', ['widget' => $gridWidget['id'] ?? null]);
					continue;
				}
				$widget['node'] = $node;
			}
			$widgets[] = $widget;
		}
		return $widgets;
	}

	/**
	 * Whether a data widget can show a node: it is part of the application, or the importer can
	 * manage it, so saving the page adds it.
	 *
	 * @param array{nodeType: int, nodeId: int} $node
	 * @param array<string, true> $nodeKeys the nodes of the application, extended by the nodes that saving adds
	 */
	private function canPlace(array $node, array &$nodeKeys, string $userId): bool {
		$key = $this->nodeKey($node['nodeType'], $node['nodeId']);
		if (isset($nodeKeys[$key])) {
			return true;
		}
		if (!$this->permissionsService->canManageNodeById($node['nodeType'], $node['nodeId'], $userId)) {
			return false;
		}
		$nodeKeys[$key] = true;
		return true;
	}

	/**
	 * The local id of a table or view a menu item names by uuid, or null when it does not exist
	 * here or the importer cannot manage it.
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

	/**
	 * A page title from a scheme, falling back to the technical name; null when there is neither.
	 */
	private function pageTitle(mixed $title, string $technicalName): string {
		$title = is_string($title) ? trim($title) : '';
		if ($title === '') {
			return $technicalName;
		}
		return mb_substr($title, 0, self::PAGE_TITLE_MAX_LENGTH);
	}

	/**
	 * The local page with the technical name, renamed to the title of the scheme, or a new page.
	 *
	 * @param array<string, int> $pagesByTechnicalName local page ids by technical name; a new page is added
	 * @throws BadRequestError|NotFoundError|PermissionError|Exception
	 */
	private function importPage(int $contextId, mixed $title, string $technicalName, array &$pagesByTechnicalName, string $userId): int {
		$title = $this->pageTitle($title, $technicalName);
		if (isset($pagesByTechnicalName[$technicalName])) {
			$this->pageService->update($contextId, $pagesByTechnicalName[$technicalName], $title, null, $userId);
			return $pagesByTechnicalName[$technicalName];
		}
		$pageId = $this->pageService->create($contextId, $title, $technicalName, $userId)['id'];
		$pagesByTechnicalName[$technicalName] = $pageId;
		return $pageId;
	}

	/**
	 * The technical name of every grid page and grid view of a scheme, keyed `page:<key>` and
	 * `gridView:<key>`.
	 *
	 * @param array<string, true> $reserved names the pages of the scheme cannot get
	 * @return array<string, string>
	 */
	private function schemeTechnicalNames(ContextScheme $scheme, array $reserved): array {
		$pages = [];
		foreach ($scheme->getPages() ?? [] as $key => $page) {
			if (!is_array($page) || ($page['page_type'] ?? $page['pageType'] ?? null) === Page::TYPE_STARTPAGE) {
				continue;
			}
			$technicalName = $page['technical_name'] ?? $page['technicalName'] ?? null;
			$pages['page:' . $key] = [
				'name' => is_string($technicalName) ? $technicalName : null,
				'fallback' => TechnicalNameHelper::pageFallback(false, is_string($page['title'] ?? null) ? $page['title'] : null),
			];
		}
		foreach ($scheme->getGridViews() as $key => $gridView) {
			$technicalName = $gridView['technicalName'] ?? $gridView['technical_name'] ?? null;
			$pages['gridView:' . $key] = [
				'name' => is_string($technicalName) ? $technicalName : null,
				'fallback' => TechnicalNameHelper::pageFallback(false, $gridView['title']),
			];
		}
		return TechnicalNameHelper::assign($pages, $reserved);
	}

	private function nodeKey(int $nodeType, int $nodeId): string {
		return sprintf('t%di%d', $nodeType, $nodeId);
	}
}
