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
use OCA\Tables\Db\Row2;
use OCA\Tables\Db\RowSleeveMapper;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Helper\InstanceTimezone;
use OCA\Tables\ResponseDefinitions;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

/**
 * Turns rows into the object shape of api/2: one property per column the row is read
 * with, keyed by its technical name and null when the row has no value for it, and the
 * metadata in `@self`. A row that was read can be written back unchanged: a value that is
 * the one its cell is read as is neither validated nor written again, also when it could
 * no longer be stored as it is, such as a cell that refers to a user, group or selection
 * option that no longer exists, a datetime stored in an unknown format, with seconds, or
 * at a wall time in a daylight saving gap (read as the moment after the jump), a value
 * outside the column's current limits or a relation outside its target view. A changed
 * value is converted and validated, and refused for a column that is read-only in the
 * view. A relation to a row that no longer exists reads as null, so writing it back
 * clears a writable relation and leaves a read-only or mandatory one as it is.
 *
 * @psalm-import-type TablesRowObject from ResponseDefinitions
 * @psalm-import-type TablesRowSelf from ResponseDefinitions
 */
class RowObjectSerializer {
	private const STORED_DATETIME_FORMATS = ['Y-m-d H:i:s', 'Y-m-d H:i'];

	/** @var list<string> the stored datetimes of the current call that have an unknown format */
	private array $unknownDatetimes = [];

	public function __construct(
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
		$this->takeUnknownDatetimes();
		$relationTargets = $this->loadRelationTargets($rows);

		$objects = [];
		foreach ($rows as $row) {
			$dataByAlias = $row->getDataByAlias();
			$values = [];
			foreach ($row->getColumnsByAlias() as $alias => $column) {
				$values[$alias] = $this->value($column, $dataByAlias[$alias]['value'] ?? null, $relationTargets);
			}
			// the union keeps @self when a column has the same key
			$objects[] = ['@self' => $this->self($row)] + $values;
		}

		$unknownDatetimes = $this->takeUnknownDatetimes();
		if ($unknownDatetimes !== []) {
			$this->logger->warning('Stored datetimes have an unknown format', [
				'count' => count($unknownDatetimes),
				'example' => $unknownDatetimes[0],
			]);
		}
		return $objects;
	}

	/**
	 * The value one stored cell is read as, in the object shape.
	 *
	 * @param array<int, ?string> $relationTargets from findRelationTargets(), for a relation cell
	 */
	public function readValue(Column $column, mixed $stored, array $relationTargets): mixed {
		$value = $this->value($column, $stored, $relationTargets);
		$this->takeUnknownDatetimes();
		return $value;
	}

	/**
	 * @return list<string> the stored datetimes with an unknown format collected since the last call
	 */
	private function takeUnknownDatetimes(): array {
		$unknownDatetimes = $this->unknownDatetimes;
		$this->unknownDatetimes = [];
		return $unknownDatetimes;
	}

	/**
	 * The metadata, with the times in UTC as they are stored, so that they can be used as
	 * they are, offset included, in the createdAt and updatedAt filters of the listing; in a
	 * query string the + of the offset is written as %2B.
	 *
	 * @return TablesRowSelf
	 */
	private function self(Row2 $row): array {
		$utc = new DateTimeZone('UTC');
		return [
			'id' => $row->getUuid(),
			'rowId' => (int)$row->getId(),
			'tableId' => (int)$row->getTableId(),
			'createdBy' => $row->getCreatedBy(),
			'createdAt' => $this->isoDatetime($row->getCreatedAt(), $utc),
			'updatedBy' => $row->getLastEditBy(),
			'updatedAt' => $this->isoDatetime($row->getLastEditAt(), $utc),
		];
	}

	/**
	 * @param array<int, ?string> $relationTargets the uuid per existing target row id, null while it has none
	 */
	private function value(Column $column, mixed $value, array $relationTargets): mixed {
		if ($value === null || $value === '') {
			return null;
		}
		return match (true) {
			$this->isPlainDatetime($column) => $this->isoDatetime((string)$value, $this->instanceTimezone->get()) ?? $value,
			$column->getType() === Column::TYPE_TEXT && $column->getSubtype() === Column::SUBTYPE_TEXT_LINK => $this->link($value),
			$column->getType() === Column::TYPE_RELATION => $this->relation((int)$value, $relationTargets),
			default => $value,
		};
	}

	/**
	 * The uuid of the target row; its row id while the target has no uuid yet (rows created
	 * before uuids get theirs from a background job); null when the target row no longer
	 * exists.
	 *
	 * @param array<int, ?string> $relationTargets
	 */
	private function relation(int $targetRowId, array $relationTargets): string|int|null {
		if (!array_key_exists($targetRowId, $relationTargets)) {
			return null;
		}
		return $relationTargets[$targetRowId] ?? $targetRowId;
	}

	private function isPlainDatetime(Column $column): bool {
		return $column->getType() === Column::TYPE_DATETIME
			&& !in_array($column->getSubtype(), [Column::SUBTYPE_DATETIME_DATE, Column::SUBTYPE_DATETIME_TIME], true);
	}

	/**
	 * A naive stored datetime, read in the given timezone, as ISO 8601 with its offset. A
	 * time in the gap of a daylight saving change does not exist; it is read as the moment
	 * the clock jumps to, one hour later (02:30 in Europe/Amsterdam on the last Sunday of
	 * March becomes 03:30+02:00).
	 */
	private function isoDatetime(?string $stored, DateTimeZone $timezone): ?string {
		if ($stored === null || $stored === '') {
			return null;
		}
		foreach (self::STORED_DATETIME_FORMATS as $format) {
			$dateTime = DateTimeImmutable::createFromFormat('!' . $format, $stored, $timezone);
			if ($dateTime !== false) {
				return $dateTime->format(DateTimeInterface::ATOM);
			}
		}
		$this->unknownDatetimes[] = $stored;
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
	 * @return array<int, ?string> the uuid per existing target row id, null while it has none
	 * @throws InternalError
	 */
	private function loadRelationTargets(array $rows): array {
		$targetRowIds = [];
		foreach ($rows as $row) {
			$dataByAlias = $row->getDataByAlias();
			foreach ($row->getColumnsByAlias() as $alias => $column) {
				$value = $dataByAlias[$alias]['value'] ?? null;
				if ($column->getType() === Column::TYPE_RELATION && is_numeric($value)) {
					$targetRowIds[] = (int)$value;
				}
			}
		}
		return $this->findRelationTargets($targetRowIds);
	}

	/**
	 * @param int[] $targetRowIds
	 * @return array<int, ?string> the uuid per existing target row id, null while it has none
	 * @throws InternalError
	 */
	public function findRelationTargets(array $targetRowIds): array {
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
