<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use OCA\Tables\AppInfo\Application;
use OCA\Tables\Db\Context;
use OCA\Tables\Db\ContextMapper;
use OCA\Tables\Db\Page;
use OCA\Tables\Db\PageContent;
use OCA\Tables\Db\PageContentMapper;
use OCA\Tables\Db\PageMapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\Helper\PageContentHelper;
use OCA\Tables\Helper\TechnicalNameHelper;
use OCA\Tables\ResponseDefinitions;
use OCP\AppFramework\Db\TTransactional;
use OCP\DB\Exception;
use OCP\IDBConnection;

/**
 * The pages of an application and the widgets on them.
 *
 * Reads need access to the application, writes need manage permissions on it.
 *
 * @psalm-import-type TablesPage from ResponseDefinitions
 * @psalm-import-type TablesPageContent from ResponseDefinitions
 */
class PageService {
	use TTransactional;

	private const TITLE_MAX_LENGTH = 200;

	public function __construct(
		private readonly ContextMapper $contextMapper,
		private readonly PageMapper $pageMapper,
		private readonly PageContentMapper $pageContentMapper,
		private readonly ContextService $contextService,
		private readonly PermissionsService $permissionsService,
		private readonly GridWidgetTypes $gridWidgetTypes,
		private readonly IDBConnection $dbc,
	) {
	}

	/**
	 * @return list<TablesPage>
	 * @throws NotFoundError
	 * @throws Exception
	 */
	public function findAll(int $contextId, string $userId): array {
		$context = $this->contextMapper->findById($contextId, $userId);
		return array_values(array_map($this->formatPage(...), $context->getPages()));
	}

	/**
	 * @return TablesPage
	 * @throws NotFoundError
	 * @throws Exception
	 */
	public function find(int $contextId, int $pageId, string $userId): array {
		$context = $this->contextMapper->findById($contextId, $userId);
		return $this->formatPage($this->getPageArray($context, $pageId));
	}

	/**
	 * @return TablesPage
	 * @throws BadRequestError
	 * @throws NotFoundError
	 * @throws PermissionError
	 * @throws Exception
	 */
	public function create(int $contextId, string $title, ?string $technicalName, string $userId): array {
		$context = $this->getManageableContext($contextId, $userId);

		$page = new Page();
		$page->setContextId($contextId);
		$page->setPageType(Page::TYPE_GRID);
		$page->setTitle($this->validateTitle($title));
		$page->setTechnicalName($technicalName === null
			? TechnicalNameHelper::unique(TechnicalNameHelper::fromText($page->getTitle() ?? ''), $this->takenTechnicalNames($context, null))
			: $this->validateTechnicalName($context, $technicalName, null));
		try {
			$page = $this->pageMapper->insert($page);
		} catch (Exception $e) {
			throw $this->technicalNameTaken($e, (string)$page->getTechnicalName());
		}

		return $this->find($contextId, $page->getId(), $userId);
	}

	/**
	 * Changes the title and technical name of a page. Null keeps the current value.
	 *
	 * @return TablesPage
	 * @throws BadRequestError
	 * @throws NotFoundError
	 * @throws PermissionError
	 * @throws Exception
	 */
	public function update(int $contextId, int $pageId, ?string $title, ?string $technicalName, string $userId): array {
		$context = $this->getManageableContext($contextId, $userId);
		$this->getPageArray($context, $pageId);

		$page = $this->pageMapper->findById($pageId);
		if ($title !== null) {
			$page->setTitle($this->validateTitle($title));
		}
		if ($technicalName !== null) {
			$page->setTechnicalName($this->validateTechnicalName($context, $technicalName, $pageId));
		}
		try {
			$this->pageMapper->update($page);
		} catch (Exception $e) {
			throw $this->technicalNameTaken($e, (string)$page->getTechnicalName());
		}

		return $this->find($contextId, $pageId, $userId);
	}

	/**
	 * Deletes a grid page and its widgets. The start page cannot be deleted.
	 *
	 * @return TablesPage the deleted page
	 * @throws BadRequestError
	 * @throws NotFoundError
	 * @throws PermissionError
	 * @throws Exception
	 */
	public function delete(int $contextId, int $pageId, string $userId): array {
		$context = $this->getManageableContext($contextId, $userId);
		$page = $this->getPageArray($context, $pageId);
		if ($page['page_type'] === Page::TYPE_STARTPAGE) {
			throw new BadRequestError('The start page of an application cannot be deleted.');
		}

		$this->atomic(function () use ($pageId): void {
			$this->pageContentMapper->deleteByPageId($pageId);
			$this->pageMapper->deleteByPageId($pageId);
		}, $this->dbc);

		return $this->formatPage($page);
	}

	/**
	 * Replaces the widgets of a page and their layout.
	 *
	 * Every widget needs a type and a grid position. A data widget names a node of the
	 * application by `nodeRelId`, or a table or view by `node: {nodeType, nodeId}`, which
	 * is added to the application first when it is not part of it yet.
	 *
	 * @param list<array<string, mixed>> $widgets
	 * @return TablesPage
	 * @throws BadRequestError
	 * @throws NotFoundError
	 * @throws PermissionError
	 * @throws Exception
	 */
	public function saveWidgets(int $contextId, int $pageId, array $widgets, string $userId): array {
		$context = $this->getManageableContext($contextId, $userId);
		$this->getPageArray($context, $pageId);

		$nodeKeys = [];
		foreach ($context->getNodes() as $relId => $node) {
			$nodeKeys[$this->nodeKey($node['node_type'], $node['node_id'])] = (int)$relId;
		}

		$rows = [];
		$nodesToAdd = [];
		foreach (array_values($widgets) as $index => $widget) {
			if (!is_array($widget)) {
				throw new BadRequestError('Widget ' . $index . ' must be an object.');
			}
			$type = $widget['type'] ?? null;
			if (!is_string($type)) {
				throw new BadRequestError('Widget ' . $index . ' needs a type.');
			}
			$row = $this->gridWidgetTypes->sanitize($type, $widget['configuration'] ?? null, $widget['content'] ?? null);
			$row['type'] = $type;
			$row['position'] = $this->validatePosition($index, $widget);
			$row['nodeKey'] = null;

			if ($type === GridWidgetTypes::TYPE_DATA) {
				[$nodeKey, $newNode] = $this->resolveDataNode($index, $widget, $context, $nodeKeys);
				$row['nodeKey'] = $nodeKey;
				if ($newNode !== null) {
					$nodesToAdd[$nodeKey] = $newNode;
				}
			}
			$rows[] = $row;
		}

		foreach ($nodesToAdd as $node) {
			if (!$this->permissionsService->canManageNodeById($node['nodeType'], $node['nodeId'], $userId)) {
				throw new PermissionError(sprintf('User %s cannot manage node %d (type %d)', $userId, $node['nodeId'], $node['nodeType']));
			}
		}

		$orders = PageContentHelper::orderFromPositions(array_map(static fn (array $row): array => $row['position'], $rows));

		$this->atomic(function () use ($context, $pageId, $rows, $nodesToAdd, $orders, $nodeKeys): void {
			foreach ($nodesToAdd as $key => $node) {
				$relation = $this->contextService->addNodeToContext($context, $node['nodeId'], $node['nodeType'], Application::PERMISSION_READ);
				$nodeKeys[$key] = $relation->getId();
			}

			$this->pageContentMapper->deleteByPageId($pageId);
			foreach ($rows as $index => $row) {
				$content = new PageContent();
				$content->setPageId($pageId);
				$content->setType($row['type']);
				$content->setNodeRelId($row['nodeKey'] !== null ? $nodeKeys[$row['nodeKey']] : null);
				$content->setConfiguration(json_encode(['configuration' => $row['configuration'], 'content' => $row['content']], JSON_THROW_ON_ERROR));
				$content->setGridX($row['position']['grid_x']);
				$content->setGridY($row['position']['grid_y']);
				$content->setGridWidth($row['position']['grid_width']);
				$content->setGridHeight($row['position']['grid_height']);
				$content->setOrder($orders[$index]);
				$this->pageContentMapper->insert($content);
			}
		}, $this->dbc);

		return $this->find($contextId, $pageId, $userId);
	}

	/**
	 * @throws NotFoundError
	 * @throws PermissionError
	 * @throws Exception
	 */
	private function getManageableContext(int $contextId, string $userId): Context {
		$context = $this->contextMapper->findById($contextId, $userId);
		if (!$this->permissionsService->canManageContextById($contextId, $userId)) {
			throw new PermissionError(sprintf('User %s cannot manage application %d', $userId, $contextId));
		}
		return $context;
	}

	/**
	 * @return array<string, mixed>
	 * @throws NotFoundError
	 */
	private function getPageArray(Context $context, int $pageId): array {
		$pages = $context->getPages();
		if (!isset($pages[$pageId])) {
			throw new NotFoundError('Page ' . $pageId . ' does not belong to application ' . $context->getId());
		}
		return $pages[$pageId];
	}

	/**
	 * @param array<string, mixed> $page
	 * @return TablesPage
	 */
	private function formatPage(array $page): array {
		$content = array_values(PageContentHelper::readContent($page['content'] ?? []));
		usort($content, static fn (array $a, array $b): int => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
		/** @var list<TablesPageContent> $content */
		$content = array_map(static fn (array $entry): array => [
			'id' => $entry['id'],
			'order' => $entry['order'],
			'node_rel_id' => $entry['node_rel_id'],
			'type' => $entry['type'],
			'configuration' => $entry['configuration'],
			'content' => $entry['content'],
			'grid_x' => $entry['grid_x'],
			'grid_y' => $entry['grid_y'],
			'grid_width' => $entry['grid_width'],
			'grid_height' => $entry['grid_height'],
		], $content);

		return [
			'id' => (int)$page['id'],
			'page_type' => (string)$page['page_type'],
			'title' => $page['title'] ?? null,
			'technical_name' => (string)($page['technical_name'] ?? ''),
			'content' => $content,
		];
	}

	/**
	 * @throws BadRequestError
	 */
	private function validateTitle(string $title): string {
		$title = trim($title);
		if ($title === '' || mb_strlen($title) > self::TITLE_MAX_LENGTH) {
			throw new BadRequestError('A page title needs 1 to ' . self::TITLE_MAX_LENGTH . ' characters.');
		}
		return $title;
	}

	/**
	 * A technical name is unique within its application.
	 *
	 * @throws BadRequestError
	 */
	private function validateTechnicalName(Context $context, string $technicalName, ?int $pageId): string {
		if (!Page::isValidTechnicalName($technicalName)) {
			throw new BadRequestError('A technical name has 1 to ' . Page::TECHNICAL_NAME_MAX_LENGTH . ' characters, starts with a lowercase letter and holds only lowercase letters, digits and underscores.');
		}
		if (isset($this->takenTechnicalNames($context, $pageId)[$technicalName])) {
			throw new BadRequestError('Another page of this application already uses the technical name ' . $technicalName . '.');
		}
		return $technicalName;
	}

	/**
	 * A write another request beat to the same technical name is a bad request;
	 * any other database error is passed on.
	 */
	private function technicalNameTaken(Exception $e, string $technicalName): BadRequestError|Exception {
		if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
			return new BadRequestError('Another page of this application already uses the technical name ' . $technicalName . '.', 0, $e);
		}
		return $e;
	}

	/**
	 * The technical names of the application's pages, apart from the given page.
	 *
	 * @return array<string, true>
	 */
	private function takenTechnicalNames(Context $context, ?int $pageId): array {
		$taken = [];
		foreach ($context->getPages() as $id => $page) {
			$technicalName = $page['technical_name'] ?? null;
			if ((int)$id !== $pageId && is_string($technicalName)) {
				$taken[$technicalName] = true;
			}
		}
		return $taken;
	}

	/**
	 * @param array<string, mixed> $widget
	 * @return array{grid_x: int, grid_y: int, grid_width: int, grid_height: int}
	 * @throws BadRequestError
	 */
	private function validatePosition(int $index, array $widget): array {
		$position = [];
		foreach (['gridX' => 'grid_x', 'gridY' => 'grid_y', 'gridWidth' => 'grid_width', 'gridHeight' => 'grid_height'] as $name => $key) {
			$value = $widget[$name] ?? null;
			$minimum = $key === 'grid_width' || $key === 'grid_height' ? 1 : 0;
			if (!is_int($value) || $value < $minimum) {
				throw new BadRequestError('Widget ' . $index . ' needs ' . $name . ' as an integer of at least ' . $minimum . '.');
			}
			$position[$key] = $value;
		}
		if ($position['grid_x'] + $position['grid_width'] > GridWidgetTypes::GRID_COLUMNS) {
			throw new BadRequestError('Widget ' . $index . ' does not fit in ' . GridWidgetTypes::GRID_COLUMNS . ' columns.');
		}
		return $position;
	}

	/**
	 * The node of a data widget: a node relation of this application, or a table or view
	 * that becomes one.
	 *
	 * @param array<string, mixed> $widget
	 * @param array<string, int> $nodeKeys relation ids of the application's nodes by node key
	 * @return array{0: string, 1: array{nodeType: int, nodeId: int}|null} the node key, and the node to add if it is new
	 * @throws BadRequestError
	 */
	private function resolveDataNode(int $index, array $widget, Context $context, array $nodeKeys): array {
		$nodeRelId = $widget['nodeRelId'] ?? null;
		if ($nodeRelId !== null) {
			$node = is_int($nodeRelId) ? ($context->getNodes()[$nodeRelId] ?? null) : null;
			if ($node === null) {
				throw new BadRequestError('Widget ' . $index . ' shows a node that is not part of this application.');
			}
			return [$this->nodeKey($node['node_type'], $node['node_id']), null];
		}

		$node = $widget['node'] ?? null;
		$nodeType = is_array($node) ? ($node['nodeType'] ?? null) : null;
		$nodeId = is_array($node) ? ($node['nodeId'] ?? null) : null;
		if (!in_array($nodeType, [Application::NODE_TYPE_TABLE, Application::NODE_TYPE_VIEW], true) || !is_int($nodeId) || $nodeId <= 0) {
			throw new BadRequestError('Widget ' . $index . ' needs a nodeRelId of this application or a node of type table or view.');
		}
		$key = $this->nodeKey($nodeType, $nodeId);
		return [$key, isset($nodeKeys[$key]) ? null : ['nodeType' => $nodeType, 'nodeId' => $nodeId]];
	}

	private function nodeKey(int $nodeType, int $nodeId): string {
		return sprintf('t%di%d', $nodeType, $nodeId);
	}
}
