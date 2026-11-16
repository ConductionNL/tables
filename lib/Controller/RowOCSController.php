<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Controller;

use OCA\Tables\AppInfo\Application;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\Helper\ConversionHelper;
use OCA\Tables\Middleware\Attribute\RequirePermission;
use OCA\Tables\Model\RowDataInput;
use OCA\Tables\ResponseDefinitions;
use OCA\Tables\Service\ContextService;
use OCA\Tables\Service\FederationService;
use OCA\Tables\Service\PermissionsService;
use OCA\Tables\Service\RowService;
use OCA\Tables\Service\TableService;
use OCA\Tables\Service\ViewService;
use OCA\Tables\Validation\SlugValidator;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * @psalm-import-type TablesRow from ResponseDefinitions
 * @psalm-import-type TablesPublicRow from ResponseDefinitions
 */
class RowOCSController extends AOCSController {

	public function __construct(
		IRequest $request,
		LoggerInterface $logger,
		IL10N $n,
		string $userId,
		protected RowService $rowService,
		private readonly TableService $tableService,
		private readonly ViewService $viewService,
		private readonly FederationService $federationService,
		private readonly ContextService $contextService,
		private readonly PermissionsService $permissionsService,
	) {
		parent::__construct($request, $logger, $n, $userId);
	}

	/**
	 * [api v2] Create a new row in a table or a view
	 *
	 * @param 'tables'|'views' $nodeCollection Indicates whether to create a row on a table or view
	 * @param int $nodeId The identifier of the targeted table or view
	 * @param string|array<string, mixed> $data An array containing the column identifiers and their values
	 * @return DataResponse<Http::STATUS_OK, TablesRow, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Row returned
	 * 400: Invalid request parameters
	 * 403: No permissions
	 * 404: Not found
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[RequirePermission(permission: Application::PERMISSION_CREATE, typeParam: 'nodeCollection')]
	#[ApiRoute(verb: 'POST', url: '/api/2/{nodeCollection}/{nodeId}/rows', requirements: ['nodeCollection' => '(tables|views)', 'nodeId' => '(\d+)'])]
	public function createRow(string $nodeCollection, int $nodeId, mixed $data): DataResponse {
		return $this->createRowInNode(ConversionHelper::stringNodeType2Const($nodeCollection), $nodeId, $data);
	}

	/**
	 * [api v2] Get a row in a table or a view
	 *
	 * @param 'tables'|'views' $nodeCollection Indicates whether to get a row from a table or view
	 * @param int $nodeId The identifier of the targeted table or view
	 * @param string $rowId The identifier of the row, either its integer id or its uuid
	 * @return DataResponse<Http::STATUS_OK, TablesRow, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Row returned
	 * 400: Invalid row identifier, or the table or view is federated
	 * 403: No permissions
	 * 404: Not found
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[RequirePermission(permission: Application::PERMISSION_READ, typeParam: 'nodeCollection')]
	#[ApiRoute(verb: 'GET', url: '/api/2/{nodeCollection}/{nodeId}/rows/{rowId}', requirements: ['nodeCollection' => '(tables|views)', 'nodeId' => '(\d+)'])]
	public function getRow(string $nodeCollection, int $nodeId, string $rowId): DataResponse {
		[$tableId, $viewId] = $this->splitNodeId(ConversionHelper::stringNodeType2Const($nodeCollection), $nodeId);
		try {
			if ($this->isFederated($tableId, $viewId)) {
				throw new BadRequestError('Getting a single row is not supported for federated tables and views.');
			}
			$resolvedRowId = $this->rowService->resolveRowId($rowId, $tableId, $viewId);
			return new DataResponse($this->rowService->findInNode($resolvedRowId, $tableId, $viewId, $this->userId)->jsonSerialize());
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (InternalError|\Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Update a row in a table or a view
	 *
	 * @param 'tables'|'views' $nodeCollection Indicates whether to update a row on a table or view
	 * @param int $nodeId The identifier of the targeted table or view
	 * @param string $rowId The identifier of the row to update, either its integer id or its uuid
	 * @param string|array<string, mixed> $data An array containing the column identifiers and their values
	 * @return DataResponse<Http::STATUS_OK, TablesRow, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Row updated
	 * 400: Invalid request parameters
	 * 403: No permissions
	 * 404: Not found
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[RequirePermission(permission: Application::PERMISSION_UPDATE, typeParam: 'nodeCollection')]
	#[ApiRoute(verb: 'PUT', url: '/api/2/{nodeCollection}/{nodeId}/rows/{rowId}', requirements: ['nodeCollection' => '(tables|views)', 'nodeId' => '(\d+)'])]
	public function updateRow(string $nodeCollection, int $nodeId, string $rowId, mixed $data): DataResponse {
		return $this->updateRowInNode(ConversionHelper::stringNodeType2Const($nodeCollection), $nodeId, $rowId, $data);
	}

	/**
	 * [api v2] Delete a row in a table or a view
	 *
	 * @param 'tables'|'views' $nodeCollection Indicates whether to delete a row on a table or view
	 * @param int $nodeId The identifier of the targeted table or view
	 * @param string $rowId The identifier of the row to delete, either its integer id or its uuid
	 * @return DataResponse<Http::STATUS_OK, TablesRow, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Row deleted
	 * 400: Invalid row identifier
	 * 403: No permissions
	 * 404: Not found
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[RequirePermission(permission: Application::PERMISSION_DELETE, typeParam: 'nodeCollection')]
	#[ApiRoute(verb: 'DELETE', url: '/api/2/{nodeCollection}/{nodeId}/rows/{rowId}', requirements: ['nodeCollection' => '(tables|views)', 'nodeId' => '(\d+)'])]
	public function deleteRow(string $nodeCollection, int $nodeId, string $rowId): DataResponse {
		return $this->deleteRowInNode(ConversionHelper::stringNodeType2Const($nodeCollection), $nodeId, $rowId);
	}

	/**
	 * [api v2] List the rows of a table or view of a context, both addressed by slug
	 *
	 * The user needs read access to the table or view, directly or through a context.
	 *
	 * @param string $contextSlug The slug of the context: lowercase letters and digits in groups separated by single dashes, starting with a letter
	 * @param string $nodeSlug The slug of the table or view within the context, by the same rule
	 * @param int|null $limit Limit the number of returned rows
	 * @param int|null $offset Skip this number of rows
	 * @return DataResponse<Http::STATUS_OK, list<TablesRow>|list<TablesPublicRow>, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Rows returned; for a federated table or view, the rows of the remote link share, without tableId, createdBy and lastEditBy
	 * 400: The slug matches more than one context
	 * 403: No permissions
	 * 404: Not found
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/2/contexts/{contextSlug}/{nodeSlug}', requirements: ['contextSlug' => SlugValidator::ROUTE_PATTERN, 'nodeSlug' => SlugValidator::ROUTE_PATTERN])]
	public function indexContextRows(string $contextSlug, string $nodeSlug, ?int $limit = null, ?int $offset = null): DataResponse {
		try {
			[$nodeType, $nodeId] = $this->resolveContextNode($contextSlug, $nodeSlug, Application::PERMISSION_READ);
			[$tableId, $viewId] = $this->splitNodeId($nodeType, $nodeId);
			if ($tableId !== null && $this->federationService->isNodeFederated($tableId, 'table')) {
				return new DataResponse($this->federationService->getRows($this->tableService->find($tableId, true), $limit, $offset));
			}
			if ($viewId !== null && $this->federationService->isNodeFederated($viewId, 'view')) {
				return new DataResponse($this->federationService->getRows($this->viewService->find($viewId, false, $this->userId), $limit, $offset));
			}
			$rows = $viewId !== null
				? $this->rowService->findAllByView($viewId, $this->userId, $limit, $offset)
				: $this->rowService->findAllByTable((int)$tableId, $this->userId, $limit, $offset);
			return new DataResponse($this->rowService->formatRows($rows));
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (InternalError|\Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Get a row of a table or view of a context, both addressed by slug
	 *
	 * The user needs read access to the table or view, directly or through a context.
	 *
	 * @param string $contextSlug The slug of the context: lowercase letters and digits in groups separated by single dashes, starting with a letter
	 * @param string $nodeSlug The slug of the table or view within the context, by the same rule
	 * @param string $rowId The identifier of the row, either its integer id or its uuid
	 * @return DataResponse<Http::STATUS_OK, TablesRow, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Row returned
	 * 400: Invalid row identifier, or the slug matches more than one context
	 * 403: No permissions
	 * 404: Not found
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/2/contexts/{contextSlug}/{nodeSlug}/{rowId}', requirements: ['contextSlug' => SlugValidator::ROUTE_PATTERN, 'nodeSlug' => SlugValidator::ROUTE_PATTERN])]
	public function showContextRow(string $contextSlug, string $nodeSlug, string $rowId): DataResponse {
		try {
			[$nodeType, $nodeId] = $this->resolveContextNode($contextSlug, $nodeSlug, Application::PERMISSION_READ);
			[$tableId, $viewId] = $this->splitNodeId($nodeType, $nodeId);
			if ($this->isFederated($tableId, $viewId)) {
				throw new BadRequestError('Getting a single row is not supported for federated tables and views.');
			}
			$resolvedRowId = $this->rowService->resolveRowId($rowId, $tableId, $viewId);
			return new DataResponse($this->rowService->findInNode($resolvedRowId, $tableId, $viewId, $this->userId)->jsonSerialize());
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (InternalError|\Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Create a row in a table or view of a context, both addressed by slug
	 *
	 * The user needs create access to the table or view, directly or through a context.
	 *
	 * @param string $contextSlug The slug of the context: lowercase letters and digits in groups separated by single dashes, starting with a letter
	 * @param string $nodeSlug The slug of the table or view within the context, by the same rule
	 * @param string|array<string, mixed> $data An array containing the column identifiers and their values
	 * @return DataResponse<Http::STATUS_OK, TablesRow, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Row returned
	 * 400: Invalid request parameters, or the slug matches more than one context
	 * 403: No permissions
	 * 404: Not found
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/2/contexts/{contextSlug}/{nodeSlug}', requirements: ['contextSlug' => SlugValidator::ROUTE_PATTERN, 'nodeSlug' => SlugValidator::ROUTE_PATTERN])]
	public function createContextRow(string $contextSlug, string $nodeSlug, mixed $data): DataResponse {
		try {
			[$nodeType, $nodeId] = $this->resolveContextNode($contextSlug, $nodeSlug, Application::PERMISSION_CREATE);
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (InternalError|\Exception $e) {
			return $this->handleError($e);
		}
		return $this->createRowInNode($nodeType, $nodeId, $data);
	}

	/**
	 * [api v2] Update a row in a table or view of a context, both addressed by slug
	 *
	 * The user needs update access to the table or view, directly or through a context.
	 *
	 * @param string $contextSlug The slug of the context: lowercase letters and digits in groups separated by single dashes, starting with a letter
	 * @param string $nodeSlug The slug of the table or view within the context, by the same rule
	 * @param string $rowId The identifier of the row to update, either its integer id or its uuid
	 * @param string|array<string, mixed> $data An array containing the column identifiers and their values
	 * @return DataResponse<Http::STATUS_OK, TablesRow, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Row updated
	 * 400: Invalid request parameters or row identifier, or the slug matches more than one context
	 * 403: No permissions
	 * 404: Not found
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/2/contexts/{contextSlug}/{nodeSlug}/{rowId}', requirements: ['contextSlug' => SlugValidator::ROUTE_PATTERN, 'nodeSlug' => SlugValidator::ROUTE_PATTERN])]
	public function updateContextRow(string $contextSlug, string $nodeSlug, string $rowId, mixed $data): DataResponse {
		try {
			[$nodeType, $nodeId] = $this->resolveContextNode($contextSlug, $nodeSlug, Application::PERMISSION_UPDATE);
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (InternalError|\Exception $e) {
			return $this->handleError($e);
		}
		return $this->updateRowInNode($nodeType, $nodeId, $rowId, $data);
	}

	/**
	 * [api v2] Delete a row in a table or view of a context, both addressed by slug
	 *
	 * The user needs delete access to the table or view, directly or through a context.
	 *
	 * @param string $contextSlug The slug of the context: lowercase letters and digits in groups separated by single dashes, starting with a letter
	 * @param string $nodeSlug The slug of the table or view within the context, by the same rule
	 * @param string $rowId The identifier of the row to delete, either its integer id or its uuid
	 * @return DataResponse<Http::STATUS_OK, TablesRow, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Row deleted
	 * 400: Invalid row identifier, or the slug matches more than one context
	 * 403: No permissions
	 * 404: Not found
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/2/contexts/{contextSlug}/{nodeSlug}/{rowId}', requirements: ['contextSlug' => SlugValidator::ROUTE_PATTERN, 'nodeSlug' => SlugValidator::ROUTE_PATTERN])]
	public function deleteContextRow(string $contextSlug, string $nodeSlug, string $rowId): DataResponse {
		try {
			[$nodeType, $nodeId] = $this->resolveContextNode($contextSlug, $nodeSlug, Application::PERMISSION_DELETE);
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (InternalError|\Exception $e) {
			return $this->handleError($e);
		}
		return $this->deleteRowInNode($nodeType, $nodeId, $rowId);
	}

	/**
	 * Resolves a node of an application by slug and runs the checks that
	 * PermissionMiddleware runs before the id routes: a node the user cannot
	 * access is not found, a missing row permission is forbidden.
	 *
	 * @return array{0: int, 1: int} the node type and the node id
	 * @throws BadRequestError|NotFoundError|PermissionError|InternalError|\OCP\DB\Exception
	 */
	private function resolveContextNode(string $contextSlug, string $nodeSlug, int $permission): array {
		$nodeRelation = $this->contextService->resolveNode($contextSlug, $nodeSlug, $this->userId);
		$nodeType = $nodeRelation->getNodeType();
		$nodeId = $nodeRelation->getNodeId();

		if (!$this->permissionsService->canAccessNodeById($nodeType, $nodeId, $this->userId)) {
			throw new NotFoundError('The application ' . $contextSlug . ' has no table or view with the slug ' . $nodeSlug . '.');
		}
		$isView = $nodeType === Application::NODE_TYPE_VIEW;
		$isAllowed = match ($permission) {
			Application::PERMISSION_CREATE => $this->permissionsService->canCreateRowsById($nodeType, $nodeId, $this->userId),
			Application::PERMISSION_UPDATE => $isView
				? $this->permissionsService->canUpdateRowsByViewId($nodeId, $this->userId)
				: $this->permissionsService->canUpdateRowsByTableId($nodeId, $this->userId),
			Application::PERMISSION_DELETE => $isView
				? $this->permissionsService->canDeleteRowsByViewId($nodeId, $this->userId)
				: $this->permissionsService->canDeleteRowsByTableId($nodeId, $this->userId),
			default => true,
		};
		if (!$isAllowed) {
			throw new PermissionError('You are not allowed to change rows of the table or view ' . $nodeSlug . '.');
		}
		return [$nodeType, $nodeId];
	}

	/**
	 * @return DataResponse<Http::STATUS_OK, TablesRow, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 */
	private function createRowInNode(int $nodeType, int $nodeId, mixed $data): DataResponse {
		if (is_string($data)) {
			$data = json_decode($data, true);
		}
		if (!is_array($data)) {
			return $this->handleBadRequestError(new BadRequestError('Cannot create row: data input is invalid.'));
		}

		[$tableId, $viewId] = $this->splitNodeId($nodeType, $nodeId);

		$newRowData = new RowDataInput();
		foreach ($data as $key => $value) {
			$newRowData->add((int)$key, $value);
		}

		try {
			if ($tableId !== null && $this->federationService->isNodeFederated($tableId, 'table')) {
				$table = $this->tableService->find($nodeId, true);
				return new DataResponse($this->federationService->createRow($table, $data));
			}
			if ($viewId !== null && $this->federationService->isNodeFederated($viewId, 'view')) {
				$view = $this->viewService->find($nodeId, false, $this->userId);
				return new DataResponse($this->federationService->createRow($view, $data));
			}
			return new DataResponse($this->rowService->create($tableId, $viewId, $newRowData)->jsonSerialize());
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (InternalError|\Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * @return DataResponse<Http::STATUS_OK, TablesRow, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 */
	private function updateRowInNode(int $nodeType, int $nodeId, string $rowId, mixed $data): DataResponse {
		if (is_string($data)) {
			$data = json_decode($data, true);
		}
		if (!is_array($data)) {
			return $this->handleBadRequestError(new BadRequestError('Cannot update row: data input is invalid.'));
		}

		[$tableId, $viewId] = $this->splitNodeId($nodeType, $nodeId);

		try {
			if ($tableId !== null && $this->federationService->isNodeFederated($tableId, 'table')) {
				$table = $this->tableService->find($nodeId, true);
				return new DataResponse($this->federationService->updateRow($table, $this->federatedRowId($rowId), $data));
			}
			if ($viewId !== null && $this->federationService->isNodeFederated($viewId, 'view')) {
				$view = $this->viewService->find($nodeId, false, $this->userId);
				return new DataResponse($this->federationService->updateRow($view, $this->federatedRowId($rowId), $data));
			}
			$resolvedRowId = $this->rowService->resolveRowId($rowId, $tableId, $viewId);
			return new DataResponse($this->rowService->updateSet($resolvedRowId, $viewId, $data, $this->userId, $tableId)->jsonSerialize());
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (InternalError|\Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * @return DataResponse<Http::STATUS_OK, TablesRow, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 */
	private function deleteRowInNode(int $nodeType, int $nodeId, string $rowId): DataResponse {
		[$tableId, $viewId] = $this->splitNodeId($nodeType, $nodeId);

		try {
			if ($tableId !== null && $this->federationService->isNodeFederated($tableId, 'table')) {
				$table = $this->tableService->find($nodeId, true);
				return new DataResponse($this->federationService->deleteRow($table, $this->federatedRowId($rowId)));
			}
			if ($viewId !== null && $this->federationService->isNodeFederated($viewId, 'view')) {
				$view = $this->viewService->find($nodeId, false, $this->userId);
				return new DataResponse($this->federationService->deleteRow($view, $this->federatedRowId($rowId)));
			}
			$resolvedRowId = $this->rowService->resolveRowId($rowId, $tableId, $viewId);
			return new DataResponse($this->rowService->delete($resolvedRowId, $viewId, $this->userId, $tableId)->jsonSerialize());
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (InternalError|\Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * @return array{0: ?int, 1: ?int} the table id and the view id, one of them null
	 */
	private function splitNodeId(int $nodeType, int $nodeId): array {
		return match ($nodeType) {
			Application::NODE_TYPE_TABLE => [$nodeId, null],
			Application::NODE_TYPE_VIEW => [null, $nodeId],
			default => [null, null],
		};
	}

	private function isFederated(?int $tableId, ?int $viewId): bool {
		if ($tableId !== null) {
			return $this->federationService->isNodeFederated($tableId, 'table');
		}
		return $viewId !== null && $this->federationService->isNodeFederated($viewId, 'view');
	}

	/**
	 * Federated tables and views are still addressed by the integer row id of the remote instance.
	 *
	 * @throws BadRequestError
	 */
	private function federatedRowId(string $rowId): int {
		if (preg_match('/^\d+$/', $rowId) !== 1) {
			throw new BadRequestError('Federated tables and views only accept an integer row id.');
		}
		return (int)$rowId;
	}
}
