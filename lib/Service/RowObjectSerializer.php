<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Tables\Db\Column;
use OCA\Tables\Db\ColumnMapper;
use OCA\Tables\Db\Row2;
use OCA\Tables\Db\RowSleeveMapper;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Helper\InstanceTimezone;
use OCA\Tables\ResponseDefinitions;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

/**
 * Turns rows into the object shape of api/2: one property per column, keyed by its
 * technical name, and the metadata in `@self`. What it returns is what
 * RowObjectInputConverter accepts back, so a row can be written back unchanged.
 *
 * @psalm-import-type TablesRowObject from ResponseDefinitions
 * @psalm-import-type TablesRowSelf from ResponseDefinitions
 */
class RowObjectSerializer {
	private const STORED_DATETIME_FORMATS = ['Y-m-d H:i:s', 'Y-m-d H:i'];

	public function __construct(
		private readonly ColumnMapper $columnMapper,
		private readonly RowSleeveMapper $rowSleeveMapper,
		private readonly InstanceTimezone $instanceTimezone,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @return TablesRowObject
	 * @throws InternalError
	 */
	public function serialize(Row2 $row): array {
		return $this->serializeAll([$row])[0];
	}

	/**
	 * @param Row2[] $rows
	 * @return list<TablesRowObject>
	 * @throws InternalError
	 */
	public function serializeAll(array $rows): array {
		$columnsById = $this->loadColumns($rows);
		$relationUuids = $this->loadRelationTargetUuids($rows, $columnsById);

		$objects = [];
		foreach ($rows as $row) {
			$object = ['@self' => $this->self($row)];
			foreach ($row->getDataByAlias() as $alias => $cell) {
				$column = $columnsById[$cell['columnId']] ?? null;
				$object[$alias] = $column === null
					? $cell['value']
					: $this->value($column, $cell['value'], $relationUuids);
			}
			$objects[] = $object;
		}
		return $objects;
	}

	/**
	 * @return TablesRowSelf
	 */
	private function self(Row2 $row): array {
		$utc = new DateTimeZone('UTC');
		return [
			'id' => $row->getUuid(),
			'rowId' => (int)$row->getId(),
			'tableId' => (int)$row->getTableId(),
			'createdBy' => (string)$row->getCreatedBy(),
			'createdAt' => $this->isoDatetime($row->getCreatedAt(), $utc),
			'lastEditBy' => (string)$row->getLastEditBy(),
			'lastEditAt' => $this->isoDatetime($row->getLastEditAt(), $utc),
		];
	}

	/**
	 * @param array<int, string> $relationUuids
	 */
	private function value(Column $column, mixed $value, array $relationUuids): mixed {
		if ($value === null || $value === '') {
			return null;
		}
		return match (true) {
			$this->isPlainDatetime($column) => $this->isoDatetime((string)$value, $this->instanceTimezone->get()) ?? $value,
			$column->getType() === Column::TYPE_TEXT && $column->getSubtype() === Column::SUBTYPE_TEXT_LINK => $this->link($value),
			$column->getType() === Column::TYPE_RELATION => $relationUuids[(int)$value] ?? (int)$value,
			default => $value,
		};
	}

	private function isPlainDatetime(Column $column): bool {
		return $column->getType() === Column::TYPE_DATETIME
			&& !in_array($column->getSubtype(), [Column::SUBTYPE_DATETIME_DATE, Column::SUBTYPE_DATETIME_TIME], true);
	}

	/**
	 * A naive stored datetime, read in the given timezone, as ISO 8601 with its offset.
	 */
	private function isoDatetime(?string $stored, DateTimeZone $timezone): ?string {
		if ($stored === null || $stored === '') {
			return null;
		}
		foreach (self::STORED_DATETIME_FORMATS as $format) {
			$dateTime = DateTimeImmutable::createFromFormat('!' . $format, $stored, $timezone);
			if ($dateTime !== false) {
				return $dateTime->setTimezone($this->instanceTimezone->get())->format(DateTimeInterface::ATOM);
			}
		}
		$this->logger->warning('Stored datetime has an unknown format', ['value' => $stored]);
		return null;
	}

	/**
	 * The stored link as {title, url, providerId}, or the stored value when it is not a link object.
	 */
	private function link(mixed $value): mixed {
		$link = is_string($value) ? json_decode($value, true) : null;
		if (!is_array($link) || !isset($link['value'])) {
			return $value;
		}
		return [
			'title' => (string)($link['title'] ?? $link['value']),
			'url' => (string)$link['value'],
			'providerId' => (string)($link['providerId'] ?? 'url'),
		];
	}

	/**
	 * @param Row2[] $rows
	 * @return array<int, Column>
	 * @throws InternalError
	 */
	private function loadColumns(array $rows): array {
		$columnIds = [];
		foreach ($rows as $row) {
			foreach ($row->getDataByAlias() as $cell) {
				$columnIds[$cell['columnId']] = true;
			}
		}
		if ($columnIds === []) {
			return [];
		}
		try {
			$columns = $this->columnMapper->findAll(array_keys($columnIds));
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			throw new InternalError(static::class . ' - ' . __FUNCTION__ . ': ' . $e->getMessage());
		}
		$columnsById = [];
		foreach ($columns as $column) {
			$columnsById[$column->getId()] = $column;
		}
		return $columnsById;
	}

	/**
	 * @param Row2[] $rows
	 * @param array<int, Column> $columnsById
	 * @return array<int, string> the uuid per target row id
	 * @throws InternalError
	 */
	private function loadRelationTargetUuids(array $rows, array $columnsById): array {
		$targetRowIds = [];
		foreach ($rows as $row) {
			foreach ($row->getDataByAlias() as $cell) {
				$column = $columnsById[$cell['columnId']] ?? null;
				if ($column?->getType() === Column::TYPE_RELATION && is_numeric($cell['value'])) {
					$targetRowIds[] = (int)$cell['value'];
				}
			}
		}
		if ($targetRowIds === []) {
			return [];
		}
		try {
			return $this->rowSleeveMapper->findUuidsByIds($targetRowIds);
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			throw new InternalError(static::class . ' - ' . __FUNCTION__ . ': ' . $e->getMessage());
		}
	}
}
