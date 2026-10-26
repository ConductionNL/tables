<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use OCA\Tables\Errors\InternalError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\JsonSchema\TableSchemaExporter;
use OCP\DB\Exception;
use OCP\IURLGenerator;

/**
 * Exports tables as JSON Schema.
 */
class TableSchemaService {
	public function __construct(
		private readonly TableService $tableService,
		private readonly ColumnService $columnService,
		private readonly TableSchemaExporter $exporter,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * @return array<string, mixed>
	 * @throws Exception
	 * @throws InternalError
	 * @throws NotFoundError
	 * @throws PermissionError
	 */
	public function export(int $tableId, string $userId): array {
		$table = $this->tableService->find($tableId, true, $userId);
		$columns = $this->columnService->findAllByTable($tableId, $userId, $table);
		return $this->exporter->export($table, $columns, $this->urlGenerator->linkToOCSRouteAbsolute('tables.TableSchema.export', ['id' => $tableId]));
	}
}
