<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Controller;

use OCA\Tables\Db\Format;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\FormatInUseError;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\ResponseDefinitions;
use OCA\Tables\Service\FormatService;
use OCA\Tables\Service\FormatValueValidator;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\Exception;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Formats that text and number columns can name as their subtype. Everyone can read the enabled
 * ones; only administrators can see disabled formats and create, change or delete formats.
 *
 * @psalm-import-type TablesFormat from ResponseDefinitions
 */
class FormatController extends AOCSController {
	public function __construct(
		IRequest $request,
		LoggerInterface $logger,
		IL10N $n,
		string $userId,
		private readonly FormatService $formatService,
		private readonly FormatValueValidator $formatValueValidator,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct($request, $logger, $n, $userId);
	}

	/**
	 * [api v2] List the formats
	 *
	 * Administrators also get the disabled formats.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<TablesFormat>, array{}>|DataResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Formats returned
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		try {
			$formats = $this->formatService->findAll($this->isAdmin());
			return new DataResponse(array_map(static fn (Format $format): array => $format->jsonSerialize(), $formats));
		} catch (Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Get a format
	 *
	 * @param int $id ID of the format
	 * @return DataResponse<Http::STATUS_OK, TablesFormat, array{}>|DataResponse<Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Format returned
	 * 404: Format not found, or disabled and the requester is no administrator
	 */
	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		try {
			return new DataResponse($this->formatService->find($id, $this->isAdmin())->jsonSerialize());
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Create a format
	 *
	 * @param string $technicalName Name a column subtype refers to; lowercase letters, digits and underscores, not the name of a built-in subtype
	 * @param string $label Name shown to people
	 * @param string $type Column type the format applies to, text or number
	 * @param array<string, mixed> $rule JSON Logic rule a value has to satisfy, read as {"var": "value"}
	 * @param string|null $example Value that satisfies the rule, shown in validation errors
	 * @param bool $enabled Whether columns can use the format
	 * @param string $version Version of the definition
	 * @return DataResponse<Http::STATUS_OK, TablesFormat, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Format created
	 * 400: Invalid definition
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function create(string $technicalName, string $label, string $type, array $rule, ?string $example = null, bool $enabled = true, string $version = '1.0.0'): DataResponse {
		try {
			return new DataResponse($this->formatService->create($technicalName, $label, $type, $rule, $example, $enabled, $version, $this->userId)->jsonSerialize());
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Change a format
	 *
	 * The technical name and type cannot be changed, so columns keep referring to the same format.
	 *
	 * @param int $id ID of the format
	 * @param string|null $label Name shown to people
	 * @param array<string, mixed>|null $rule JSON Logic rule a value has to satisfy
	 * @param string|null $example Value that satisfies the rule; an empty string removes it
	 * @param bool|null $enabled Whether columns can use the format
	 * @param string|null $version Version of the definition
	 * @return DataResponse<Http::STATUS_OK, TablesFormat, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Format changed
	 * 400: Invalid definition
	 * 404: Format not found
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function update(int $id, ?string $label = null, ?array $rule = null, ?string $example = null, ?bool $enabled = null, ?string $version = null): DataResponse {
		try {
			return new DataResponse($this->formatService->update($id, $label, $rule, $example, $enabled, $version, $this->userId)->jsonSerialize());
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (Exception $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Preview a change to a format
	 *
	 * Shows how many columns use the format and how many of their stored values would fail the
	 * changed definition, without storing it. Stored values stay readable after the change, but
	 * writes of failing values are refused.
	 *
	 * @param int $id ID of the format
	 * @param string|null $label Name shown to people
	 * @param array<string, mixed>|null $rule JSON Logic rule a value has to satisfy
	 * @param string|null $example Value that satisfies the rule
	 * @param string|null $version Version of the definition
	 * @return DataResponse<Http::STATUS_OK, array{columnsCount: int, failingValuesCount: int}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Effect of the change returned
	 * 400: Invalid definition
	 * 404: Format not found
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function previewUpdate(int $id, ?string $label = null, ?array $rule = null, ?string $example = null, ?string $version = null): DataResponse {
		try {
			$changedFormat = $this->formatService->previewUpdate($id, $label, $rule, $example, $version);
			return new DataResponse($this->formatValueValidator->previewFormatChange($changedFormat));
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (Exception|InternalError $e) {
			return $this->handleError($e);
		}
	}

	/**
	 * [api v2] Delete a format
	 *
	 * Columns that use it keep their values and are reported as broken. When columns use the
	 * format, the request has to confirm how many: without that number it answers 409 with it.
	 * Formats shipped with Tables cannot be deleted, only disabled.
	 *
	 * @param int $id ID of the format
	 * @param int|null $confirmBrokenColumns Number of columns that will break, as the 409 response gave it
	 * @return DataResponse<Http::STATUS_OK, TablesFormat, array{}>|DataResponse<Http::STATUS_CONFLICT, array{message: string, brokenColumnsCount: int}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{message: string}, array{}>
	 *
	 * 200: Format deleted
	 * 400: Format is shipped with Tables
	 * 404: Format not found
	 * 409: Columns use the format and their number was not confirmed
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function destroy(int $id, ?int $confirmBrokenColumns = null): DataResponse {
		try {
			return new DataResponse($this->formatService->delete($id, $confirmBrokenColumns)->jsonSerialize());
		} catch (FormatInUseError $e) {
			return new DataResponse([
				'message' => $this->n->n('Removing this format breaks %n column.', 'Removing this format breaks %n columns.', $e->brokenColumnsCount),
				'brokenColumnsCount' => $e->brokenColumnsCount,
			], Http::STATUS_CONFLICT);
		} catch (BadRequestError $e) {
			return $this->handleBadRequestError($e);
		} catch (NotFoundError $e) {
			return $this->handleNotFoundError($e);
		} catch (Exception $e) {
			return $this->handleError($e);
		}
	}

	private function isAdmin(): bool {
		return $this->groupManager->isAdmin($this->userId);
	}
}
