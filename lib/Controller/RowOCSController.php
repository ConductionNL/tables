<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Controller;

use OCA\Tables\AppInfo\Application;
use OCA\Tables\Db\Row2;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\Errors\RowValidationError;
use OCA\Tables\Helper\ConversionHelper;
use OCA\Tables\Middleware\Attribute\RequirePermission;
use OCA\Tables\Model\RowDataInput;
use OCA\Tables\Model\RowListQuery;
use OCA\Tables\Model\RowObjectInput;
use OCA\Tables\Model\RowShape;
use OCA\Tables\ResponseDefinitions;
use OCA\Tables\Service\FederationService;
use OCA\Tables\Service\RowObjectSerializer;
use OCA\Tables\Service\RowObjectXmlShaper;
use OCA\Tables\Service\RowService;
use OCA\Tables\Service\TableService;
use OCA\Tables\Service\ViewService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * @psalm-import-type TablesRow from ResponseDefinitions
 * @psalm-import-type TablesRowPage from ResponseDefinitions
 * @psalm-import-type TablesRowObject from ResponseDefinitions
 * @psalm-import-type TablesRowObjectPage from ResponseDefinitions
 * @psalm-import-type TablesRowValidationError from ResponseDefinitions
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
		private readonly RowObjectSerializer $rowObjectSerializer,
		private readonly RowObjectXmlShaper $rowObjectXmlShaper,
	) {
		parent::__construct($request, $logger, $n, $userId);
	}

	/**
	 * [api v2] Create a new row in a table or a view
	 *
	 * @param 'tables'|'views' $nodeCollection Indicates whether to create a row on a table or view
	 * @param int $nodeId The identifier of the targeted table or view
	 * @param string|array<string, mixed> $data The values of the row: keyed by column id, or with shape "object" keyed by technical name
	 * @param 'cells'|'object'|null $shape The shape of the data and of the returned row, defaults to "cells". The data is sent as JSON or form data; in an XML response a row in the object shape has an element per column, see the shape of getRow
	 * @return DataResponse<Http::STATUS_OK, TablesRow|TablesRowObject, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}|TablesRowValidationError, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Row returned
	 * 400: Invalid request parameters or an unknown shape (nothing is written then), or with shape "object" the values that cannot be stored, per technical name; in XML errors has an element per technical name, by the rules of the object shape
	 * 403: No permissions
	 * 404: Not found
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[RequirePermission(permission: Application::PERMISSION_CREATE, typeParam: 'nodeCollection')]
	#[ApiRoute(verb: 'POST', url: '/api/2/{nodeCollection}/{nodeId}/rows', requirements: ['nodeCollection' => '(tables|views)', 'nodeId' => '(\d+)'])]
	public function createRow(string $nodeCollection, int $nodeId, mixed $data, ?string $shape = null): DataResponse {
		try {
			$rowShape = RowShape::fromRequest($shape);
		} catch (\InvalidArgumentException $e) {
			return $this->handleBadRequestError(new BadRequestError($e->getMessage()));
		}
		if (is_string($data)) {
			$data = json_decode($data, true);
		}
		if (!is_array($data)) {
			return $this->handleBadRequestError(new BadRequestError('Cannot create row: data input is invalid.'));
		}

		$iNodeType = ConversionHelper::stringNodeType2Const($nodeCollection);
		$tableId = $viewId = null;
		if ($iNodeType === Application::NODE_TYPE_TABLE) {
			$tableId = $nodeId;
		} elseif ($iNodeType === Application::NODE_TYPE_VIEW) {
			$viewId = $nodeId;
		}

		if ($rowShape === RowShape::Object) {
			try {
				$newRowData = new RowObjectInput($data);
			} catch (\InvalidArgumentException $e) {
				return $this->handleBadRequestError(new BadRequestError($e->getMessage()));
			}
		} else {
			$newRowData = new RowDataInput();
			foreach ($data as $key => $value) {
				$newRowData->add((int)$key, $value);
			}
		}

		try {
			if ($tableId !== null && $this->federationService->isNodeFederated($tableId, 'table')) {
				$this->refuseObjectShapeOnFederation($rowShape);
				$table = $this->tableService->find($nodeId, true);
				return new DataResponse($this->federationService->createRow($table, $data));
			}
			if ($viewId !== null && $this->federationService->isNodeFederated($viewId, 'view')) {
				$this->refuseObjectShapeOnFederation($rowShape);
				$view = $this->viewService->find($nodeId, false, $this->userId);
				return new DataResponse($this->federationService->createRow($view, $data));
			}
			return new DataResponse($this->shapeRow($this->rowService->create($tableId, $viewId, $newRowData), $rowShape));
		} catch (RowValidationError $e) {
			return $this->rowValidationError($e);
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
	 * [api v2] List the rows of a table or a view
	 *
	 * Filters, search and sort apply to the columns the table or view shows and to the meta
	 * fields, which every row carries; on a view they narrow its own filter, never widen it.
	 * Filter values and the search term are compared as given, placeholders such as @me only
	 * work in the view's own filter.
	 *
	 * @param 'tables'|'views' $nodeCollection Indicates whether to list the rows of a table or view
	 * @param int $nodeId The identifier of the targeted table or view
	 * @param string|null $page The page to return, an integer of at least 1, defaults to 1
	 * @param string|null $offset The number of rows to skip, an alternative to page
	 * @param string|null $limit The number of rows per page, an integer of at least 1, defaults to 25, a limit above 500 is lowered to 500
	 * @param string|null $sort Comma separated technical names, column ids or meta fields (id, createdBy, createdAt, updatedBy, updatedAt), a minus sorts descending. Defaults to the sort of the table or view
	 * @param string|null $search Text to look for in the text cells and the selection labels
	 * @param string|null $fields Comma separated technical names or column ids of the columns to return, the metadata of a row is always returned
	 * @param array<string, string|array<string, string>>|null $filter Filters as filter[field]=value or filter[field][operator]=value, all of which must match. A deep object in the query string (for example filter[age][is-greater-than]=30&filter[name]=jane), shown as a string because the API description cannot express nested query parameters. field is a technical name, a column id or a meta field; operator is one of the view filter operators that fit the field's type (without one: is-equal, or contains-item on multi-value columns; long and rich text need an operator such as contains); a value is compared as given and written as the column stores it: a decimal number; a row id for relations and id; YYYY-MM-DD HH:MM for date and time columns, YYYY-MM-DD for date columns, HH:MM for time columns; YYYY-MM-DD with an optional HH:MM or HH:MM:SS, after a space or a T, for createdAt and updatedAt, in UTC unless the time ends in Z or an offset such as +02:00 (written %2B02:00 in the query string, where + means a space), an exact moment, so compare those with is-greater-than and is-lower-than rather than is-equal; an option id for selections; true or false for checkboxes; a user, group or team id for usergroup columns
	 * @param 'cells'|'object'|null $shape The shape of the returned rows, defaults to "cells". With "object" a row is one object with a property per column, keyed by its technical name and null without a value: datetimes with the offset of the instance timezone, links as {title, url, providerId}, relations as the uuid of the target row (its row id while it has no uuid yet, null when it no longer exists), and the metadata in @self, with its times in UTC. In XML a row is an element holding <self/>, with the metadata as attributes and an attribute without a value left out, and an element per column named by its technical name; a name that is not an XML NCName, or is element or self, becomes <element name="...">, which holds the value in <value> unless it is a list or an object. null is an empty element with nil="true", unprefixed because xsi:nil would need a namespace declaration; an empty string or list is an empty element without it. Booleans are true and false, a list has an <element> per item and an object a child element per property, by the same rules
	 * @return DataResponse<Http::STATUS_OK, TablesRowPage|TablesRowObjectPage, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Rows returned
	 * 400: Invalid query parameters, or the table or view is federated
	 * 403: No permissions
	 * 404: Not found
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[RequirePermission(permission: Application::PERMISSION_READ, typeParam: 'nodeCollection')]
	#[ApiRoute(verb: 'GET', url: '/api/2/{nodeCollection}/{nodeId}/rows', requirements: ['nodeCollection' => '(tables|views)', 'nodeId' => '(\d+)'])]
	public function getRows(
		string $nodeCollection,
		int $nodeId,
		?string $page = null,
		?string $offset = null,
		?string $limit = null,
		?string $sort = null,
		?string $search = null,
		?string $fields = null,
		mixed $filter = null,
		?string $shape = null,
	): DataResponse {
		[$tableId, $viewId] = $this->splitNode($nodeCollection, $nodeId);
		try {
			if ($this->isFederated($tableId, $viewId)) {
				throw new BadRequestError('Listing rows is not supported for federated tables and views yet.');
			}
			try {
				$query = RowListQuery::createFromRequest($page, $offset, $limit, $sort, $search, $fields, $filter);
				$rowShape = RowShape::fromRequest($shape);
			} catch (\InvalidArgumentException $e) {
				throw new BadRequestError($e->getMessage());
			}

			$found = $this->rowService->findPage($query, $tableId, $viewId, $this->userId);
			return new DataResponse([
				'results' => $rowShape === RowShape::Object
					? $this->rowObjects($found['rows'])
					: array_values(array_map(static fn ($row) => $row->jsonSerialize(), $found['rows'])),
				'total' => $found['total'],
				'limit' => $query->limit,
				'page' => $query->getPage(),
				'pages' => max(1, (int)ceil($found['total'] / $query->limit)),
			]);
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
	 * [api v2] Get a row in a table or a view
	 *
	 * @param 'tables'|'views' $nodeCollection Indicates whether to get a row from a table or view
	 * @param int $nodeId The identifier of the targeted table or view
	 * @param string $rowId The identifier of the row, either its integer id or its uuid
	 * @param 'cells'|'object'|null $shape The shape of the returned row, defaults to "cells". With "object" a row is one object with a property per column, keyed by its technical name and null without a value: datetimes with the offset of the instance timezone, links as {title, url, providerId}, relations as the uuid of the target row (its row id while it has no uuid yet, null when it no longer exists), and the metadata in @self, with its times in UTC. In XML a row is an element holding <self/>, with the metadata as attributes and an attribute without a value left out, and an element per column named by its technical name; a name that is not an XML NCName, or is element or self, becomes <element name="...">, which holds the value in <value> unless it is a list or an object. null is an empty element with nil="true", unprefixed because xsi:nil would need a namespace declaration; an empty string or list is an empty element without it. Booleans are true and false, a list has an <element> per item and an object a child element per property, by the same rules
	 * @return DataResponse<Http::STATUS_OK, TablesRow|TablesRowObject, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Row returned
	 * 400: Invalid row identifier, an unknown shape, or the table or view is federated
	 * 403: No permissions
	 * 404: Not found
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[RequirePermission(permission: Application::PERMISSION_READ, typeParam: 'nodeCollection')]
	#[ApiRoute(verb: 'GET', url: '/api/2/{nodeCollection}/{nodeId}/rows/{rowId}', requirements: ['nodeCollection' => '(tables|views)', 'nodeId' => '(\d+)'])]
	public function getRow(string $nodeCollection, int $nodeId, string $rowId, ?string $shape = null): DataResponse {
		[$tableId, $viewId] = $this->splitNode($nodeCollection, $nodeId);
		try {
			try {
				$rowShape = RowShape::fromRequest($shape);
			} catch (\InvalidArgumentException $e) {
				throw new BadRequestError($e->getMessage());
			}
			if ($this->isFederated($tableId, $viewId)) {
				throw new BadRequestError('Getting a single row is not supported for federated tables and views.');
			}
			$resolvedRowId = $this->rowService->resolveRowId($rowId, $tableId, $viewId);
			return new DataResponse($this->shapeRow($this->rowService->findInNode($resolvedRowId, $tableId, $viewId, $this->userId), $rowShape));
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
	 * @param string|array<string, mixed> $data The values to change: keyed by column id, or with shape "object" keyed by technical name
	 * @param 'cells'|'object'|null $shape The shape of the data and of the returned row, defaults to "cells". The data is sent as JSON or form data; in an XML response a row in the object shape has an element per column, see the shape of getRow
	 * @return DataResponse<Http::STATUS_OK, TablesRow|TablesRowObject, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}|TablesRowValidationError, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Row updated
	 * 400: Invalid request parameters or an unknown shape (nothing is written then), or with shape "object" the values that cannot be stored, per technical name; in XML errors has an element per technical name, by the rules of the object shape
	 * 403: No permissions
	 * 404: Not found
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[RequirePermission(permission: Application::PERMISSION_UPDATE, typeParam: 'nodeCollection')]
	#[ApiRoute(verb: 'PUT', url: '/api/2/{nodeCollection}/{nodeId}/rows/{rowId}', requirements: ['nodeCollection' => '(tables|views)', 'nodeId' => '(\d+)'])]
	public function updateRow(string $nodeCollection, int $nodeId, string $rowId, mixed $data, ?string $shape = null): DataResponse {
		try {
			$rowShape = RowShape::fromRequest($shape);
		} catch (\InvalidArgumentException $e) {
			return $this->handleBadRequestError(new BadRequestError($e->getMessage()));
		}
		if (is_string($data)) {
			$data = json_decode($data, true);
		}
		if (!is_array($data)) {
			return $this->handleBadRequestError(new BadRequestError('Cannot update row: data input is invalid.'));
		}
		try {
			$values = $rowShape === RowShape::Object ? new RowObjectInput($data) : $data;
		} catch (\InvalidArgumentException $e) {
			return $this->handleBadRequestError(new BadRequestError($e->getMessage()));
		}
		[$tableId, $viewId] = $this->splitNode($nodeCollection, $nodeId);
		try {
			if ($tableId !== null && $this->federationService->isNodeFederated($tableId, 'table')) {
				$this->refuseObjectShapeOnFederation($rowShape);
				$table = $this->tableService->find($nodeId, true);
				return new DataResponse($this->federationService->updateRow($table, $this->federatedRowId($rowId), $data));
			}
			if ($viewId !== null && $this->federationService->isNodeFederated($viewId, 'view')) {
				$this->refuseObjectShapeOnFederation($rowShape);
				$view = $this->viewService->find($nodeId, false, $this->userId);
				return new DataResponse($this->federationService->updateRow($view, $this->federatedRowId($rowId), $data));
			}
			$resolvedRowId = $this->rowService->resolveRowId($rowId, $tableId, $viewId);
			return new DataResponse($this->shapeRow($this->rowService->updateSet($resolvedRowId, $viewId, $values, $this->userId, $tableId), $rowShape));
		} catch (RowValidationError $e) {
			return $this->rowValidationError($e);
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
		[$tableId, $viewId] = $this->splitNode($nodeCollection, $nodeId);
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
	 * OCS answers in XML unless the format parameter or else the Accept header asks for JSON.
	 */
	private function isXmlResponse(): bool {
		return $this->request->getFormat() !== 'json';
	}

	/**
	 * @return TablesRow|TablesRowObject
	 * @throws InternalError
	 */
	private function shapeRow(Row2 $row, RowShape $rowShape): array {
		if ($rowShape === RowShape::Cells) {
			return $row->jsonSerialize();
		}
		$object = $this->rowObjectSerializer->serialize($row);
		return $this->isXmlResponse() ? $this->rowObjectXmlShaper->shapeRow($object) : $object;
	}

	/**
	 * @param Row2[] $rows
	 * @return list<TablesRowObject>
	 * @throws InternalError
	 */
	private function rowObjects(array $rows): array {
		$objects = $this->rowObjectSerializer->serializeAll(array_values($rows));
		return $this->isXmlResponse() ? $this->rowObjectXmlShaper->shapeRows($objects) : $objects;
	}

	/**
	 * @return DataResponse<Http::STATUS_BAD_REQUEST, TablesRowValidationError, array{}>
	 */
	private function rowValidationError(RowValidationError $e): DataResponse {
		$response = $this->handleRowValidationError($e);
		if ($this->isXmlResponse()) {
			$response->setData($this->rowObjectXmlShaper->shapeValidationError($response->getData()));
		}
		return $response;
	}

	/**
	 * @throws BadRequestError when a federated table or view is written in the object shape
	 */
	private function refuseObjectShapeOnFederation(RowShape $rowShape): void {
		if ($rowShape === RowShape::Object) {
			throw new BadRequestError('The object shape is not supported for federated tables and views.');
		}
	}

	/**
	 * @param 'tables'|'views' $nodeCollection
	 * @return array{0: ?int, 1: ?int} the table id and the view id, one of them is null
	 */
	private function splitNode(string $nodeCollection, int $nodeId): array {
		$iNodeType = ConversionHelper::stringNodeType2Const($nodeCollection);
		if ($iNodeType === Application::NODE_TYPE_TABLE) {
			return [$nodeId, null];
		}
		if ($iNodeType === Application::NODE_TYPE_VIEW) {
			return [null, $nodeId];
		}
		return [null, null];
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
