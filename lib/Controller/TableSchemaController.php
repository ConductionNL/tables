<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Controller;

use OCA\Tables\AppInfo\Application;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\Middleware\Attribute\RequirePermission;
use OCA\Tables\Service\TableSchemaService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\Exception;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Tables as JSON Schema 2020-12, for clients and tools that describe objects that way.
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

}
