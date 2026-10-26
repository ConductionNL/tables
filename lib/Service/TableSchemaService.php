<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use DateTime;
use OCA\Tables\Db\Column;
use OCA\Tables\Db\ColumnMapper;
use OCA\Tables\Db\Format;
use OCA\Tables\Db\Table;
use OCA\Tables\Dto\Column as ColumnDto;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\FormatImportConflictError;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\JsonSchema\ParsedTableSchema;
use OCA\Tables\JsonSchema\TableSchemaExporter;
use OCA\Tables\JsonSchema\TableSchemaParser;
use OCA\Tables\ResponseDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use Throwable;

/**
 * Exports tables as JSON Schema and imports JSON Schema as a new table or into an existing one.
 *
 * The format definitions a schema carries are installed as custom formats when they are missing.
 * When one differs from an installed format, an administrator chooses to keep or replace it; for
 * anyone else the installed format is kept and a warning says so. Core formats are never changed.
 * Columns whose format stays missing keep their subtype and are broken.
 *
 * @psalm-import-type TablesFormatPackItem from ResponseDefinitions
 * @psalm-import-type TablesSchemaImport from ResponseDefinitions
 * @psalm-import-type TablesSchemaChanges from ResponseDefinitions
 */
class TableSchemaService {
	/** What a schema can change of a column; the type cannot change, as it decides how values are stored */
	private const COMPARED_FIELDS = [
		'title', 'description', 'subtype', 'mandatory',
		'textDefault', 'textAllowedPattern', 'textMaxLength', 'textUnique',
		'numberDefault', 'numberMin', 'numberMax', 'numberDecimals', 'numberPrefix', 'numberSuffix',
		'selectionOptions', 'selectionDefault', 'datetimeDefault',
		'usergroupDefault', 'usergroupMultipleItems', 'usergroupSelectUsers', 'usergroupSelectGroups', 'usergroupSelectTeams', 'showUserStatus',
		'customSettings',
	];

	public function __construct(
		private readonly TableService $tableService,
		private readonly ColumnService $columnService,
		private readonly ColumnMapper $columnMapper,
		private readonly FormatService $formatService,
		private readonly FormatPackService $formatPackService,
		private readonly TableSchemaExporter $exporter,
		private readonly TableSchemaParser $parser,
		private readonly IGroupManager $groupManager,
		private readonly IDBConnection $db,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l,
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
		return $this->exporter->export($table, $columns, $this->schemaUrl($tableId));
	}

	/**
	 * Creates a table from the schema.
	 *
	 * @param array<array-key, mixed> $schema
	 * @param array<string, string> $resolutions keep or replace, by technical name of a format
	 * @return TablesSchemaImport
	 * @throws BadRequestError
	 * @throws FormatImportConflictError
	 * @throws InternalError
	 */
	public function importAsNewTable(array $schema, array $resolutions, string $userId): array {
		$parsed = $this->parser->parse($schema);
		[$resolutions, $warnings] = $this->resolveFormats($parsed, $resolutions, $userId);

		return $this->inTransaction(function () use ($parsed, $resolutions, $warnings, $userId): array {
			$formatResult = $this->formatPackService->importDefinitions($parsed->formatDefinitions, Format::SOURCE_CUSTOM, $resolutions, $userId);
			$table = $this->tableService->create($parsed->title, 'custom', $parsed->emoji, $parsed->description, $userId);
			foreach ($parsed->columns as $column) {
				$this->columnService->importColumn($table, $this->withTimestamps($this->withFreeUuid($column)));
			}
			return [
				'table' => $this->tableService->find($table->getId(), false, $userId)->jsonSerialize(),
				'formats' => $formatResult,
				'brokenColumns' => $this->brokenColumns($parsed->columns),
				'warnings' => $warnings,
			];
		});
	}

	/**
	 * What importing the schema into the table would change, without changing anything.
	 *
	 * @param array<array-key, mixed> $schema
	 * @return TablesSchemaChanges
	 * @throws BadRequestError
	 * @throws Exception
	 * @throws InternalError
	 * @throws NotFoundError
	 * @throws PermissionError
	 */
	public function previewChanges(int $tableId, array $schema, string $userId): array {
		$table = $this->tableService->find($tableId, true, $userId);
		$parsed = $this->parser->parse($schema);
		$changes = $this->columnChanges($table, $parsed, $userId);
		$formats = $this->formatPackService->previewDefinitions($parsed->formatDefinitions, Format::SOURCE_CUSTOM);

		return [
			'title' => ['from' => $table->getTitle(), 'to' => $parsed->title],
			'description' => ['from' => (string)$table->getDescription(), 'to' => $parsed->description],
			'columns' => [
				'add' => array_map(self::summary(...), $changes['add']),
				'modify' => array_map(static fn (array $change): array => ['technicalName' => $change['column']->getTechnicalName(), 'title' => $change['column']->getTitle(), 'changes' => $change['changes']], $changes['modify']),
				'remove' => array_map(static fn (Column $column): array => ['technicalName' => (string)$column->getTechnicalName(), 'title' => $column->getTitle(), 'type' => $column->getType(), 'subtype' => $column->getSubtype()], $changes['remove']),
			],
			'formats' => $formats,
			'brokenColumns' => $this->brokenColumns($parsed->columns),
			'hasChanges' => $changes['add'] !== [] || $changes['modify'] !== [] || $changes['remove'] !== []
				|| $table->getTitle() !== $parsed->title || (string)$table->getDescription() !== $parsed->description
				|| array_filter($formats, static fn (array $item): bool => $item['status'] !== FormatPackService::STATUS_UNCHANGED) !== [],
		];
	}

	/**
	 * Imports the schema into the table: columns it has are changed, new ones are added and columns
	 * it does not have are removed, as the preview showed.
	 *
	 * @param array<array-key, mixed> $schema
	 * @param array<string, string> $resolutions keep or replace, by technical name of a format
	 * @return TablesSchemaImport
	 * @throws BadRequestError
	 * @throws Exception
	 * @throws FormatImportConflictError
	 * @throws InternalError
	 * @throws NotFoundError
	 * @throws PermissionError
	 */
	public function importIntoTable(int $tableId, array $schema, array $resolutions, string $userId): array {
		$table = $this->tableService->find($tableId, true, $userId);
		$parsed = $this->parser->parse($schema);
		$changes = $this->columnChanges($table, $parsed, $userId);
		[$resolutions, $warnings] = $this->resolveFormats($parsed, $resolutions, $userId);

		return $this->inTransaction(function () use ($table, $parsed, $changes, $resolutions, $warnings, $userId): array {
			$formatResult = $this->formatPackService->importDefinitions($parsed->formatDefinitions, Format::SOURCE_CUSTOM, $resolutions, $userId);
			$this->tableService->update($table->getId(), $parsed->title, $parsed->emoji ?? $table->getEmoji(), $parsed->description, null, $userId);
			foreach ($changes['add'] as $column) {
				$this->columnService->importColumn($table, $this->withTimestamps($this->withFreeUuid($column)));
			}
			foreach ($changes['modify'] as $change) {
				$this->columnService->update($change['column']->getId(), $userId, ColumnDto::createFromArray($change['to']), allowMissingFormat: true);
			}
			foreach ($changes['remove'] as $column) {
				$this->columnService->delete($column->getId(), false, $userId);
			}
			return [
				'table' => $this->tableService->find($table->getId(), false, $userId)->jsonSerialize(),
				'formats' => $formatResult,
				'brokenColumns' => $this->brokenColumns($parsed->columns),
				'warnings' => $warnings,
			];
		});
	}

	/**
	 * The resolutions for the format definitions of the schema, completed for someone who may not
	 * change formats: they keep what is installed. Also what the import says about the formats.
	 *
	 * @param array<string, string> $resolutions
	 * @return array{0: array<string, string>, 1: list<string>} the resolutions and the warnings
	 * @throws FormatImportConflictError when an administrator has not chosen for every conflict
	 * @throws InternalError
	 */
	private function resolveFormats(ParsedTableSchema $parsed, array $resolutions, string $userId): array {
		try {
			$items = $this->formatPackService->previewDefinitions($parsed->formatDefinitions, Format::SOURCE_CUSTOM);
		} catch (Exception $e) {
			throw new InternalError($e->getMessage(), 0, $e);
		}
		$isAdmin = $this->groupManager->isAdmin($userId);
		$warnings = [];
		$unresolved = [];
		foreach ($items as $item) {
			$technicalName = $item['technicalName'];
			if ($item['status'] === FormatPackService::STATUS_CORE) {
				$warnings[] = $this->l->t('Tables ships the format %1$s, so the definition in the schema is not used.', [$technicalName]);
			} elseif (in_array($item['status'], [FormatPackService::STATUS_UPDATE, FormatPackService::STATUS_CONFLICT], true)) {
				if (!$isAdmin) {
					$resolutions[$technicalName] = FormatPackService::RESOLUTION_KEEP;
					$warnings[] = $this->l->t('The format %1$s in the schema differs from the installed one, which is used. Ask an administrator to import the changed format.', [$technicalName]);
				} elseif (!isset($resolutions[$technicalName])) {
					$unresolved[] = $item;
				}
			}
		}
		if ($unresolved !== []) {
			throw new FormatImportConflictError($unresolved);
		}
		return [$resolutions, $warnings];
	}

	/**
	 * The columns of the schema matched to the columns of the table, by uuid and else by technical
	 * name, and what would change for each.
	 *
	 * @return array{add: list<array<string, mixed>>, modify: list<array{column: Column, to: array<string, mixed>, changes: array<string, array{from: mixed, to: mixed}>}>, remove: list<Column>}
	 * @throws BadRequestError when a column would change its type
	 * @throws Exception
	 * @throws InternalError
	 * @throws NotFoundError
	 * @throws PermissionError
	 */
	private function columnChanges(Table $table, ParsedTableSchema $parsed, string $userId): array {
		$current = $this->columnService->findAllByTable($table->getId(), $userId, $table);
		// read the table back through the parser as a client would send it, so both sides are compared in the same shape
		$export = json_decode(json_encode($this->exporter->export($table, $current, $this->schemaUrl($table->getId())), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
		// the installed definitions are not compared here, and are not checked again either
		foreach ($export['properties'] as $name => $property) {
			unset($export['properties'][$name]['x-tables-format-definition']);
		}
		$currentParsed = $this->parser->parse($export)->columns;

		$byUuid = [];
		$byTechnicalName = [];
		foreach ($current as $index => $column) {
			$byUuid[(string)$column->getUuid()] = $index;
			$byTechnicalName[(string)$column->getTechnicalName()] = $index;
		}

		$add = [];
		$modify = [];
		$matched = [];
		foreach ($parsed->columns as $incoming) {
			$index = $byUuid[(string)$incoming['uuid']] ?? $byTechnicalName[(string)$incoming['technicalName']] ?? null;
			if ($index === null || isset($matched[$index])) {
				$add[] = $incoming;
				continue;
			}
			$matched[$index] = true;
			$column = $current[$index];
			if ($incoming['type'] !== $column->getType()) {
				throw new BadRequestError($this->l->t('The property %1$s would change the column %2$s from %3$s to %4$s, which an import cannot do.', [(string)$incoming['technicalName'], $column->getTitle(), $column->getType(), (string)$incoming['type']]));
			}
			$differences = [];
			foreach (self::COMPARED_FIELDS as $field) {
				if ($currentParsed[$index][$field] != $incoming[$field]) {
					$differences[$field] = ['from' => $currentParsed[$index][$field], 'to' => $incoming[$field]];
				}
			}
			if ($differences !== []) {
				$modify[] = ['column' => $column, 'to' => [...$incoming, 'technicalName' => $column->getTechnicalName()], 'changes' => $differences];
			}
		}
		$remove = array_values(array_filter($current, static fn (Column $column, int $index): bool => !isset($matched[$index]), ARRAY_FILTER_USE_BOTH));
		return ['add' => $add, 'modify' => $modify, 'remove' => $remove];
	}

	/**
	 * The technical names of the columns whose format will not be installed after the import.
	 *
	 * @param list<array<string, mixed>> $columns
	 * @return list<string>
	 * @throws InternalError
	 */
	private function brokenColumns(array $columns): array {
		$broken = [];
		foreach ($columns as $column) {
			$formatName = Column::formatNameOf((string)$column['type'], (string)$column['subtype']);
			try {
				$usable = $formatName === null || $this->formatService->findUsableFormat($formatName, (string)$column['type']) !== null;
			} catch (Exception $e) {
				throw new InternalError($e->getMessage(), 0, $e);
			}
			if (!$usable) {
				$broken[] = (string)$column['technicalName'];
			}
		}
		return $broken;
	}

	/**
	 * Column uuids are unique across all tables, so a schema imported next to its own table gets new ones.
	 *
	 * @param array<string, mixed> $column
	 * @return array<string, mixed>
	 */
	private function withFreeUuid(array $column): array {
		if ($column['uuid'] === null) {
			return $column;
		}
		try {
			$this->columnMapper->findByUuid((string)$column['uuid']);
			$column['uuid'] = null;
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
		}
		return $column;
	}

	/**
	 * @param array<string, mixed> $column
	 * @return array<string, mixed>
	 */
	private function withTimestamps(array $column): array {
		$now = (new DateTime())->format('Y-m-d H:i:s');
		return [...$column, 'createdAt' => $now, 'lastEditAt' => $now];
	}

	/**
	 * @param array<string, mixed> $column
	 * @return array{technicalName: string, title: string, type: string, subtype: string}
	 */
	private static function summary(array $column): array {
		return ['technicalName' => (string)$column['technicalName'], 'title' => (string)$column['title'], 'type' => (string)$column['type'], 'subtype' => (string)$column['subtype']];
	}

	private function schemaUrl(int $tableId): string {
		return $this->urlGenerator->linkToOCSRouteAbsolute('tables.TableSchema.export', ['id' => $tableId]);
	}

	/**
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 * @throws InternalError
	 */
	private function inTransaction(callable $work): mixed {
		$this->db->beginTransaction();
		try {
			$result = $work();
			$this->db->commit();
			return $result;
		} catch (Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof BadRequestError || $e instanceof FormatImportConflictError || $e instanceof InternalError || $e instanceof PermissionError) {
				throw $e;
			}
			throw new InternalError($e->getMessage(), 0, $e);
		}
	}
}
