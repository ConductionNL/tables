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
use OCA\Tables\Db\PageContentMapper;
use OCA\Tables\Db\PageMapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\Helper\TechnicalNameHelper;
use OCA\Tables\ResponseDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\TTransactional;
use OCP\DB\Exception;
use OCP\IDBConnection;

/**
 * The pages of an application and the widgets on them.
 *
 * Reads need access to the application, writes need manage permissions on it.
 *
 * @psalm-import-type TablesPage from ResponseDefinitions
 * @psalm-import-type TablesWidgetValue from ResponseDefinitions
 * @psalm-type TablesWidgetInput = array{id: ?int, type: string, title: ?string, position: array{gridX: int, gridY: int, gridWidth: int, gridHeight: int}, settings: array<string, TablesWidgetValue>, nodeRelId: ?int, nodeKey: ?string}
 */
class PageService {
	use TTransactional;

	private const TITLE_MAX_LENGTH = 200;
	private const EMOJI_MAX_LENGTH = 32;
	private const DESCRIPTION_MAX_LENGTH = 2000;
	private const GRID_MAX_HEIGHT = 100;
	private const GRID_MAX_Y = 10000;

	public function __construct(
		private readonly ContextMapper $contextMapper,
		private readonly PageMapper $pageMapper,
		private readonly PageContentMapper $pageContentMapper,
		private readonly ContextService $contextService,
		private readonly WidgetService $widgetService,
		private readonly PermissionsService $permissionsService,
		private readonly GridWidgetTypes $gridWidgetTypes,
		private readonly IDBConnection $dbc,
	) {
	}

	/**
	 * @return TablesPage
	 * @throws NotFoundError
	 * @throws Exception
	 */
	public function find(int $contextId, int $pageId, string $userId): array {
		$context = $this->contextMapper->findById($contextId, $userId);
		$page = $this->getPageArray($context, $pageId);
		$widgets = $this->widgetService->findByPageIds([$pageId]);

		return [
			'id' => $pageId,
			'contextId' => $contextId,
			'pageType' => (string)$page['page_type'],
			'title' => $page['title'] ?? null,
			'emoji' => $page['emoji'] ?? null,
			'description' => $page['description'] ?? null,
			'technicalName' => (string)($page['technical_name'] ?? ''),
			'widgets' => $widgets[$pageId] ?? [],
		];
	}

	/**
	 * Adds a grid page to an application. Without a technical name the page gets one
	 * made from its title.
	 *
	 * @return TablesPage
	 * @throws BadRequestError
	 * @throws NotFoundError
	 * @throws PermissionError
	 * @throws Exception
	 */
	public function create(int $contextId, string $title, ?string $emoji, ?string $description, ?string $technicalName, string $userId): array {
		$context = $this->getManageableContext($contextId, $userId);

		$page = new Page();
		$page->setContextId($contextId);
		$page->setPageType(Page::TYPE_GRID);
		$page->setTitle($this->validateTitle($title));
		$page->setEmoji($this->validateEmoji($emoji));
		$page->setDescription($this->validateDescription($description));
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
	 * Changes the fields of a page and, when widgets are given, makes its widgets match
	 * them in one transaction. A null argument keeps the current value; an empty emoji
	 * or description removes it.
	 *
	 * A widget has a `type`, an optional `id` of a widget of this page, a `title`,
	 * `gridX`, `gridY`, `gridWidth` and `gridHeight` on a grid of 12 columns and
	 * `settings` following the schema of its type. A widget of a type with a source has
	 * a `source`: `{nodeRelId}` of a node of this application, `{nodeType, nodeId}` of a
	 * table or view that is added to the application, or null.
	 *
	 * @param list<mixed>|null $widgets
	 * @return TablesPage
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws BadRequestError
	 * @throws NotFoundError
	 * @throws PermissionError
	 * @throws Exception
	 */
	public function update(int $contextId, int $pageId, ?string $title, ?string $emoji, ?string $description, ?array $widgets, string $userId, ?string $technicalName = null): array {
		$context = $this->getManageableContext($contextId, $userId);
		$this->getPageArray($context, $pageId);

		$page = $this->pageMapper->findById($pageId);
		if ($title !== null) {
			$page->setTitle($this->validateTitle($title));
		}
		if ($emoji !== null) {
			$page->setEmoji($this->validateEmoji($emoji));
		}
		if ($description !== null) {
			$page->setDescription($this->validateDescription($description));
		}
		if ($technicalName !== null) {
			$page->setTechnicalName($this->validateTechnicalName($context, $technicalName, $pageId));
		}

		$rows = null;
		$nodesToAdd = [];
		if ($widgets !== null) {
			[$rows, $nodesToAdd] = $this->readWidgets($widgets, $context, $pageId, $userId);
		}

		$this->atomic(function () use ($page, $rows, $nodesToAdd, $contextId, $pageId, $userId): void {
			if ($page->getUpdatedFields() !== []) {
				try {
					$this->pageMapper->update($page);
				} catch (Exception $e) {
					throw $this->technicalNameTaken($e, (string)$page->getTechnicalName());
				}
			}
			if ($rows === null) {
				return;
			}
			$addedRelIds = [];
			foreach ($nodesToAdd as $key => $node) {
				[$relation] = $this->contextService->addNodeToContextAndStartpage($contextId, $node['nodeId'], $node['nodeType'], Application::PERMISSION_READ, 100, $userId);
				$addedRelIds[$key] = $relation->getId();
			}
			$this->widgetService->syncPage($pageId, array_map(static function (array $row) use ($addedRelIds): array {
				if ($row['nodeKey'] !== null) {
					$row['nodeRelId'] = $addedRelIds[$row['nodeKey']];
				}
				unset($row['nodeKey']);
				return $row;
			}, $rows));
		}, $this->dbc);

		return $this->find($contextId, $pageId, $userId);
	}

	/**
	 * Deletes a grid page with its widgets. The start page cannot be deleted.
	 *
	 * @return TablesPage the deleted page
	 * @throws BadRequestError
	 * @throws NotFoundError
	 * @throws PermissionError
	 * @throws Exception
	 */
	public function delete(int $contextId, int $pageId, string $userId): array {
		$context = $this->getManageableContext($contextId, $userId);
		$pageArray = $this->getPageArray($context, $pageId);
		if ($pageArray['page_type'] !== Page::TYPE_GRID) {
			throw new BadRequestError('The start page of an application cannot be deleted.');
		}
		$page = $this->find($contextId, $pageId, $userId);

		$this->atomic(function () use ($pageId): void {
			$this->widgetService->deleteByPageIds([$pageId]);
			$this->pageContentMapper->deleteByPageId($pageId);
			$this->pageMapper->deleteByPageId($pageId);
		}, $this->dbc);

		return $page;
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
		$pages = $context->getPages() ?? [];
		if (!isset($pages[$pageId])) {
			throw new NotFoundError('Page ' . $pageId . ' does not belong to application ' . $context->getId());
		}
		return $pages[$pageId];
	}

	/**
	 * @param list<mixed> $widgets
	 * @return array{0: list<TablesWidgetInput>, 1: array<string, array{nodeType: int, nodeId: int}>} the widgets, and the nodes to add by node key
	 * @throws BadRequestError
	 * @throws PermissionError
	 * @throws Exception
	 */
	private function readWidgets(array $widgets, Context $context, int $pageId, string $userId): array {
		$existingIds = array_flip($this->widgetService->findIdsByPageId($pageId));
		$relIdsByNodeKey = [];
		foreach ($context->getNodes() ?? [] as $relId => $node) {
			$relIdsByNodeKey[$this->nodeKey((int)$node['node_type'], (int)$node['node_id'])] = (int)$relId;
		}

		$rows = [];
		$nodesToAdd = [];
		$seenIds = [];
		foreach (array_values($widgets) as $index => $widget) {
			if (!is_array($widget)) {
				throw new BadRequestError('Widget ' . $index . ' must be an object.');
			}
			$type = $widget['type'] ?? null;
			if (!is_string($type)) {
				throw new BadRequestError('Widget ' . $index . ' needs a type.');
			}
			$typeSchema = $this->gridWidgetTypes->get($type);

			$id = $widget['id'] ?? null;
			if ($id !== null && (!is_int($id) || !isset($existingIds[$id]) || isset($seenIds[$id]))) {
				throw new BadRequestError('Widget ' . $index . ' has the id of no other widget on this page.');
			}
			if ($id !== null) {
				$seenIds[$id] = true;
			}

			$row = [
				'id' => $id,
				'type' => $type,
				'title' => $this->validateWidgetTitle($index, $widget['title'] ?? null),
				'position' => $this->validatePosition($index, $widget),
				'settings' => $this->gridWidgetTypes->sanitizeSettings($type, $widget['settings'] ?? null),
				'nodeRelId' => null,
				'nodeKey' => null,
			];

			$source = $widget['source'] ?? null;
			if ($source !== null) {
				if (!$typeSchema['hasSource']) {
					throw new BadRequestError('Widget ' . $index . ' is a ' . $type . ' widget, which shows no table or view.');
				}
				[$row['nodeRelId'], $newNode] = $this->resolveSource($index, $source, $context, $relIdsByNodeKey);
				if ($newNode !== null) {
					$row['nodeKey'] = $this->nodeKey($newNode['nodeType'], $newNode['nodeId']);
					$nodesToAdd[$row['nodeKey']] = $newNode;
				}
			}
			$rows[] = $row;
		}

		foreach ($nodesToAdd as $node) {
			if (!$this->permissionsService->canManageNodeById($node['nodeType'], $node['nodeId'], $userId)) {
				throw new PermissionError(sprintf('User %s cannot manage node %d (type %d)', $userId, $node['nodeId'], $node['nodeType']));
			}
		}

		return [$rows, $nodesToAdd];
	}

	/**
	 * The node relation of a data widget, or the table or view to add to the application.
	 *
	 * @param array<string, int> $relIdsByNodeKey
	 * @return array{0: ?int, 1: ?array{nodeType: int, nodeId: int}}
	 * @throws BadRequestError
	 */
	private function resolveSource(int $index, mixed $source, Context $context, array $relIdsByNodeKey): array {
		if (!is_array($source)) {
			throw new BadRequestError('The source of widget ' . $index . ' must be an object.');
		}
		if (array_key_exists('nodeRelId', $source)) {
			$nodeRelId = $source['nodeRelId'];
			if (!is_int($nodeRelId) || !isset(($context->getNodes() ?? [])[$nodeRelId])) {
				throw new BadRequestError('Widget ' . $index . ' shows a table or view that is not part of this application.');
			}
			return [$nodeRelId, null];
		}

		$nodeType = $source['nodeType'] ?? null;
		$nodeId = $source['nodeId'] ?? null;
		if (!in_array($nodeType, [Application::NODE_TYPE_TABLE, Application::NODE_TYPE_VIEW], true) || !is_int($nodeId) || $nodeId <= 0) {
			throw new BadRequestError('The source of widget ' . $index . ' needs a nodeRelId of this application, or a nodeType and nodeId of a table or view.');
		}
		$key = $this->nodeKey($nodeType, $nodeId);
		if (isset($relIdsByNodeKey[$key])) {
			return [$relIdsByNodeKey[$key], null];
		}
		return [null, ['nodeType' => $nodeType, 'nodeId' => $nodeId]];
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
	 * A write that another request beat to the same technical name is a bad request;
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
		foreach ($context->getPages() ?? [] as $id => $page) {
			$technicalName = $page['technical_name'] ?? null;
			if ((int)$id !== $pageId && is_string($technicalName)) {
				$taken[$technicalName] = true;
			}
		}
		return $taken;
	}

	/**
	 * @throws BadRequestError
	 */
	private function validateEmoji(?string $emoji): ?string {
		$emoji = trim((string)$emoji);
		if (mb_strlen($emoji) > self::EMOJI_MAX_LENGTH) {
			throw new BadRequestError('An emoji has at most ' . self::EMOJI_MAX_LENGTH . ' characters.');
		}
		return $emoji === '' ? null : $emoji;
	}

	/**
	 * @throws BadRequestError
	 */
	private function validateDescription(?string $description): ?string {
		$description = trim((string)$description);
		if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
			throw new BadRequestError('A page description has at most ' . self::DESCRIPTION_MAX_LENGTH . ' characters.');
		}
		return $description === '' ? null : $description;
	}

	/**
	 * @throws BadRequestError
	 */
	private function validateWidgetTitle(int $index, mixed $title): ?string {
		if ($title === null) {
			return null;
		}
		if (!is_string($title) || mb_strlen(trim($title)) > self::TITLE_MAX_LENGTH) {
			throw new BadRequestError('The title of widget ' . $index . ' must be a text of at most ' . self::TITLE_MAX_LENGTH . ' characters.');
		}
		$title = trim($title);
		return $title === '' ? null : $title;
	}

	/**
	 * @param array<array-key, mixed> $widget
	 * @return array{gridX: int, gridY: int, gridWidth: int, gridHeight: int}
	 * @throws BadRequestError
	 */
	private function validatePosition(int $index, array $widget): array {
		$limits = [
			'gridX' => [0, GridWidgetTypes::GRID_COLUMNS - 1],
			'gridY' => [0, self::GRID_MAX_Y],
			'gridWidth' => [1, GridWidgetTypes::GRID_COLUMNS],
			'gridHeight' => [1, self::GRID_MAX_HEIGHT],
		];
		$position = [];
		foreach ($limits as $name => [$minimum, $maximum]) {
			$value = $widget[$name] ?? null;
			if (!is_int($value) || $value < $minimum || $value > $maximum) {
				throw new BadRequestError('Widget ' . $index . ' needs ' . $name . ' as a whole number from ' . $minimum . ' to ' . $maximum . '.');
			}
			$position[$name] = $value;
		}
		if ($position['gridX'] + $position['gridWidth'] > GridWidgetTypes::GRID_COLUMNS) {
			throw new BadRequestError('Widget ' . $index . ' does not fit in ' . GridWidgetTypes::GRID_COLUMNS . ' columns.');
		}
		return $position;
	}

	private function nodeKey(int $nodeType, int $nodeId): string {
		return sprintf('t%di%d', $nodeType, $nodeId);
	}
}
