<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Controller;

use OCA\Tables\AppInfo\Application;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\FormatImportConflictError;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\Middleware\Attribute\RequirePermission;
use OCA\Tables\ResponseDefinitions;
use OCA\Tables\Service\TableSchemaService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\Exception;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Tables as JSON Schema 2020-12, for clients and tools that describe objects that way.
 *
 * @psalm-import-type TablesFormatPackItem from ResponseDefinitions
 * @psalm-import-type TablesSchemaImport from ResponseDefinitions
 * @psalm-import-type TablesSchemaChanges from ResponseDefinitions
 */
class TableSchemaController extends AOCSController {
	public function __construct(
		IRequest $request,
		LoggerInterface $logger,
		IL10N $n,
		string $userId,
		private readonly TableSchemaService $schemaService,
	) {
		parent::__construct($request, $logger, $n, $userId);
	}

	/**
	 * [api v2] Export a table as JSON Schema
	 *
	 * The schema describes a row as the object shape reads and writes it, with one property per
	 * column. Standard keywords carry what JSON Schema can express; the rest travels in x-tables-*
	 * keywords, so the export imports back without loss.
	 *
	 * @param int $id Table ID
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: JSON Schema of the table
	 * 403: No permissions
	 * 404: Not found
	 */
	#[NoAdminRequired]
	#[RequirePermission(permission: Application::PERMISSION_READ, type: Application::NODE_TYPE_TABLE, idParam: 'id')]
	public function export(int $id): DataResponse {
		try {
			return new DataResponse($this->schemaService->export($id, $this->userId));
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (Exception|InternalError $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Create a table from a JSON Schema
	 *
	 * Accepts JSON Schema 2020-12 and draft-07. Format definitions the schema carries are installed
	 * as custom formats when they are missing. When one differs from an installed format, an
	 * administrator gets 409 with those formats and chooses with resolution[{technicalName}]=keep
	 * or replace; for anyone else the installed format is kept and a warning says so. Columns whose
	 * format stays missing keep their subtype and are broken.
	 *
	 * @param array<string, mixed> $schema The JSON Schema of the table
	 * @param array<string, string> $resolution keep or replace, by technical name of a format
	 * @return DataResponse<Http::STATUS_OK, TablesSchemaImport, array{}>|DataResponse<Http::STATUS_CONFLICT, array{message: string, conflicts: list<TablesFormatPackItem>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Table created
	 * 400: The schema cannot be imported, with the property that is the reason
	 * 409: Formats would change and have no resolution
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function importAsNewTable(array $schema, array $resolution = []): DataResponse {
		try {
			return new DataResponse($this->schemaService->importAsNewTable($schema, $resolution, $this->userId));
		} catch (FormatImportConflictError $e) {
			return $this->conflictResponse($e);
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (InternalError $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Preview importing a JSON Schema into a table
	 *
	 * Shows the columns that would be added, changed and removed, the format definitions that would
	 * be installed or conflict, and the columns that would be broken, without changing anything.
	 * Columns are matched by x-tables-uuid and otherwise by technical name.
	 *
	 * @param int $id Table ID
	 * @param array<string, mixed> $schema The JSON Schema of the table
	 * @return DataResponse<Http::STATUS_OK, TablesSchemaChanges, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: What the import would change
	 * 400: The schema cannot be imported, with the property that is the reason
	 * 403: No permissions
	 * 404: Not found
	 */
	#[NoAdminRequired]
	#[RequirePermission(permission: Application::PERMISSION_MANAGE, type: Application::NODE_TYPE_TABLE, idParam: 'id')]
	public function previewChanges(int $id, array $schema): DataResponse {
		try {
			return new DataResponse($this->schemaService->previewChanges($id, $schema, $this->userId));
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (Exception|InternalError $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Import a JSON Schema into a table
	 *
	 * Applies what the preview shows: columns the schema has are changed, new ones are added and
	 * columns it does not have are removed. Formats are handled as when creating a table.
	 *
	 * @param int $id Table ID
	 * @param array<string, mixed> $schema The JSON Schema of the table
	 * @param array<string, string> $resolution keep or replace, by technical name of a format
	 * @return DataResponse<Http::STATUS_OK, TablesSchemaImport, array{}>|DataResponse<Http::STATUS_CONFLICT, array{message: string, conflicts: list<TablesFormatPackItem>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Table changed
	 * 400: The schema cannot be imported, with the property that is the reason
	 * 403: No permissions
	 * 404: Not found
	 * 409: Formats would change and have no resolution
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	#[RequirePermission(permission: Application::PERMISSION_MANAGE, type: Application::NODE_TYPE_TABLE, idParam: 'id')]
	public function importIntoTable(int $id, array $schema, array $resolution = []): DataResponse {
		try {
			return new DataResponse($this->schemaService->importIntoTable($id, $schema, $resolution, $this->userId));
		} catch (FormatImportConflictError $e) {
			return $this->conflictResponse($e);
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (PermissionError $e) {
			return $this->handlePermissionError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (Exception|InternalError $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * @return DataResponse<Http::STATUS_CONFLICT, array{message: string, conflicts: list<TablesFormatPackItem>}, array{}>
	 */
	private function conflictResponse(FormatImportConflictError $e): DataResponse {
		return new DataResponse([
			'message' => $this->n->n('%n format in the schema differs from the installed one; choose to keep or replace it.', '%n formats in the schema differ from the installed ones; choose to keep or replace each.', count($e->conflicts)),
			'conflicts' => $e->conflicts,
		], Http::STATUS_CONFLICT);
	}
}
