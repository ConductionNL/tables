<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Controller;

use OCA\Tables\AppInfo\Application;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\Middleware\Attribute\RequirePermission;
use OCA\Tables\ResponseDefinitions;
use OCA\Tables\Service\GridWidgetTypes;
use OCA\Tables\Service\PageService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\Exception;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * @psalm-import-type TablesPage from ResponseDefinitions
 * @psalm-import-type TablesGridWidgetType from ResponseDefinitions
 */
class ContextPageController extends AOCSController {
	public function __construct(
		IRequest $request,
		LoggerInterface $logger,
		IL10N $n,
		string $userId,
		private readonly PageService $pageService,
		private readonly GridWidgetTypes $gridWidgetTypes,
	) {
		parent::__construct($request, $logger, $n, $userId);
	}

	/**
	 * [api v2] Get the pages of an application with their widgets
	 *
	 * @param int $contextId ID of the application
	 * @return DataResponse<Http::STATUS_OK, list<TablesPage>, array{}>|DataResponse<Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Pages returned
	 * 404: Not found
	 */
	#[NoAdminRequired]
	#[RequirePermission(permission: Application::PERMISSION_READ, typeParam: 'context', idParam: 'contextId')]
	public function index(int $contextId): DataResponse {
		try {
			return new DataResponse($this->pageService->findAll($contextId, $this->userId));
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Get one page of an application with its widgets
	 *
	 * Content without a position is placed full width below the positioned widgets.
	 *
	 * @param int $contextId ID of the application
	 * @param int $pageId ID of the page
	 * @return DataResponse<Http::STATUS_OK, TablesPage, array{}>|DataResponse<Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Page returned
	 * 404: Not found
	 */
	#[NoAdminRequired]
	#[RequirePermission(permission: Application::PERMISSION_READ, typeParam: 'context', idParam: 'contextId')]
	public function show(int $contextId, int $pageId): DataResponse {
		try {
			return new DataResponse($this->pageService->find($contextId, $pageId, $this->userId));
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Create a grid page in an application
	 *
	 * @param int $contextId ID of the application
	 * @param string $title Title of the page
	 * @param string|null $technicalName Name to address the page by, unique within the application; null makes one from the title
	 * @return DataResponse<Http::STATUS_OK, TablesPage, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Page created
	 * 400: Invalid title or technical name
	 * 403: No permissions
	 * 404: Not found
	 *
	 * @CanManageContext
	 */
	#[NoAdminRequired]
	public function create(int $contextId, string $title, ?string $technicalName = null): DataResponse {
		try {
			return new DataResponse($this->pageService->create($contextId, $title, $technicalName, $this->userId));
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Change the title or technical name of a page
	 *
	 * @param int $contextId ID of the application
	 * @param int $pageId ID of the page
	 * @param string|null $title New title, null keeps it
	 * @param string|null $technicalName New technical name, unique within the application; null keeps it
	 * @return DataResponse<Http::STATUS_OK, TablesPage, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Page updated
	 * 400: Invalid title or technical name
	 * 403: No permissions
	 * 404: Not found
	 *
	 * @CanManageContext
	 */
	#[NoAdminRequired]
	public function update(int $contextId, int $pageId, ?string $title = null, ?string $technicalName = null): DataResponse {
		try {
			return new DataResponse($this->pageService->update($contextId, $pageId, $title, $technicalName, $this->userId));
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (DoesNotExistException|MultipleObjectsReturnedException $e) {
			return $this->handleNotFoundError(new NotFoundError($e->getMessage(), $e->getCode(), $e));
		} catch (Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Delete a grid page with its widgets
	 *
	 * The start page of an application cannot be deleted.
	 *
	 * @param int $contextId ID of the application
	 * @param int $pageId ID of the page
	 * @return DataResponse<Http::STATUS_OK, TablesPage, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Page deleted
	 * 400: The page is the start page
	 * 403: No permissions
	 * 404: Not found
	 *
	 * @CanManageContext
	 */
	#[NoAdminRequired]
	public function destroy(int $contextId, int $pageId): DataResponse {
		try {
			return new DataResponse($this->pageService->delete($contextId, $pageId, $this->userId));
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Save the widgets of a page and their layout
	 *
	 * Replaces every widget of the page. Each widget has a `type`, a `configuration` and
	 * a `content` following the schemas of its type, and `gridX`, `gridY`, `gridWidth`
	 * and `gridHeight` on a grid of 12 columns. A data widget names either `nodeRelId`, a
	 * node of this application, or `node` as `{nodeType, nodeId}`, a table or view that is
	 * added to the application with read permissions when it is not part of it yet.
	 *
	 * @param int $contextId ID of the application
	 * @param int $pageId ID of the page
	 * @param list<array<string, mixed>> $widgets The widgets of the page
	 * @return DataResponse<Http::STATUS_OK, TablesPage, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Widgets saved
	 * 400: Invalid widget
	 * 403: No permissions on the application or on a table or view to add
	 * 404: Not found
	 *
	 * @CanManageContext
	 */
	#[NoAdminRequired]
	public function saveWidgets(int $contextId, int $pageId, array $widgets = []): DataResponse {
		try {
			return new DataResponse($this->pageService->saveWidgets($contextId, $pageId, $widgets, $this->userId));
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] The widget types a page can hold
	 *
	 * Every type carries two schemas: the configuration of the widget on the page and its content.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<TablesGridWidgetType>, array{}>
	 *
	 * 200: Widget types returned
	 */
	#[NoAdminRequired]
	public function widgetTypes(): DataResponse {
		return new DataResponse(array_values($this->gridWidgetTypes->all()));
	}
}
