<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Controller;

use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\FormatImportConflictError;
use OCA\Tables\Errors\FormatInUseError;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Formats\FormatPackReader;
use OCA\Tables\ResponseDefinitions;
use OCA\Tables\Service\FormatPackService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\Exception;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The format packs Tables ships, such as the formats of a country. Only administrators can see,
 * install and remove them.
 *
 * @psalm-import-type TablesFormatPack from ResponseDefinitions
 * @psalm-import-type TablesFormatPackItem from ResponseDefinitions
 * @psalm-import-type TablesFormatPackImport from ResponseDefinitions
 */
class FormatPackController extends AOCSController {
	public function __construct(
		IRequest $request,
		LoggerInterface $logger,
		IL10N $n,
		string $userId,
		private readonly FormatPackService $packService,
		private readonly FormatPackReader $packReader,
	) {
		parent::__construct($request, $logger, $n, $userId);
	}

	/**
	 * [api v2] List the format packs Tables ships
	 *
	 * @return DataResponse<Http::STATUS_OK, list<TablesFormatPack>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Packs returned, with how much of each is installed and whether it has newer rules
	 * 400: A shipped pack cannot be read
	 */
	public function index(): DataResponse {
		try {
			return new DataResponse($this->packService->listShippedPacks());
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Preview importing a format pack
	 *
	 * @param string $name Name of the pack, such as nl
	 * @return DataResponse<Http::STATUS_OK, list<TablesFormatPackItem>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: What the import would do to each format of the pack
	 * 400: No such pack, or it cannot be read
	 */
	public function preview(string $name): DataResponse {
		try {
			return new DataResponse($this->packService->preview($this->packReader->readShipped($name)));
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (Exception|InternalError $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Import a format pack
	 *
	 * Formats that would change an installed one need a choice. Without it the import answers 409
	 * with those formats; the next request carries resolution[{technicalName}]=keep or replace for
	 * each of them.
	 *
	 * @param string $name Name of the pack, such as nl
	 * @param array<string, string> $resolution keep or replace, by technical name
	 * @return DataResponse<Http::STATUS_OK, TablesFormatPackImport, array{}>|DataResponse<Http::STATUS_CONFLICT, array{message: string, conflicts: list<TablesFormatPackItem>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Pack imported
	 * 400: No such pack, it cannot be read, or a resolution is not keep or replace
	 * 409: Formats would change and have no resolution
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function import(string $name, array $resolution = []): DataResponse {
		try {
			return new DataResponse($this->packService->import($this->packReader->readShipped($name), $resolution, $this->userId));
		} catch (FormatImportConflictError $e) {
			return new DataResponse([
				'message' => $this->n->n('%n format would change; choose to keep or replace it.', '%n formats would change; choose to keep or replace each.', count($e->conflicts)),
				'conflicts' => $e->conflicts,
			], Http::STATUS_CONFLICT);
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (Exception|InternalError $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Remove a format pack
	 *
	 * Removes exactly the formats installed from the pack. Columns that use them keep their values
	 * and are reported as broken; when there are such columns, the request has to confirm how many.
	 *
	 * @param string $name Name of the pack, such as nl
	 * @param int|null $confirmBrokenColumns Number of columns that will break, as the 409 response gave it
	 * @return DataResponse<Http::STATUS_OK, array{removed: list<string>}, array{}>|DataResponse<Http::STATUS_CONFLICT, array{message: string, brokenColumnsCount: int}, array{}>|DataResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Formats of the pack removed
	 * 409: Columns use the formats and their number was not confirmed
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function destroy(string $name, ?int $confirmBrokenColumns = null): DataResponse {
		try {
			return new DataResponse(['removed' => $this->packService->remove($name, $confirmBrokenColumns)]);
		} catch (FormatInUseError $e) {
			return new DataResponse([
				'message' => $this->n->n('Removing this pack breaks %n column.', 'Removing this pack breaks %n columns.', $e->brokenColumnsCount),
				'brokenColumnsCount' => $e->brokenColumnsCount,
			], Http::STATUS_CONFLICT);
		} catch (Exception $e) {
			return $this->handleError($e);
		}
	}
}
