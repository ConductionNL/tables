<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use DateTimeImmutable;
use OCA\Tables\Db\Column;
use OCA\Tables\Db\Row2Mapper;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Helper\InstanceTimezone;
use OCA\Tables\Model\RowDataInput;
use OCA\Tables\Model\RowObjectInput;

/**
 * Turns a row in the object shape into cells keyed by column id, in the input format the
 * column types already parse. It reverses RowObjectSerializer, so what was read can be
 * written back unchanged, and it reports every property it cannot convert instead of
 * dropping it.
 */
class RowObjectInputConverter {
	private const SELF_KEY = '@self';
	private const ISO_DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/';
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	public function __construct(
		private readonly Row2Mapper $row2Mapper,
		private readonly InstanceTimezone $instanceTimezone,
	) {
	}

	/**
	 * @param Column[] $columns the columns of the table or view that is written to
	 * @return array{cells: RowDataInput, errors: array<string, string>}
	 * @throws InternalError
	 */
	public function toCells(RowObjectInput $input, array $columns): array {
		$columnsByName = [];
		foreach ($columns as $column) {
			$columnsByName[self::nameOf($column)] = $column;
		}

		$cells = new RowDataInput();
		$errors = [];
		foreach ($input->values as $name => $value) {
			$name = (string)$name;
			if ($name === self::SELF_KEY) {
				continue;
			}
			$column = $columnsByName[$name] ?? null;
			if ($column === null) {
				$errors[$name] = 'There is no column with this technical name.';
				continue;
			}
			$converted = $this->convert($column, $value);
			if ($converted['error'] !== null) {
				$errors[$name] = $converted['error'];
				continue;
			}
			$cells->add($column->getId(), $converted['value']);
		}
		return ['cells' => $cells, 'errors' => $errors];
	}

	/**
	 * The name a column has in the object shape: its technical name, or `column_{id}`
	 * for a column that has none.
	 */
	public static function nameOf(Column $column): string {
		$technicalName = $column->getTechnicalName();
		return $technicalName !== null && $technicalName !== '' ? $technicalName : 'column_' . $column->getId();
	}

	/**
	 * @return array{value: array|string|int|float|null, error: ?string}
	 * @throws InternalError
	 */
	private function convert(Column $column, mixed $value): array {
		if ($value === null) {
			return ['value' => null, 'error' => null];
		}
		return match (true) {
			$column->getType() === Column::TYPE_DATETIME
				&& !in_array($column->getSubtype(), [Column::SUBTYPE_DATETIME_DATE, Column::SUBTYPE_DATETIME_TIME], true) => $this->datetime($value),
			$column->getType() === Column::TYPE_TEXT && $column->getSubtype() === Column::SUBTYPE_TEXT_LINK => $this->link($value),
			$column->getType() === Column::TYPE_NUMBER => $this->number($value),
			$column->getType() === Column::TYPE_RELATION => $this->relation($value),
			$column->getType() === Column::TYPE_USERGROUP => $this->usergroups($value),
			$column->getSubtype() === Column::SUBTYPE_SELECTION_CHECK && is_bool($value) => ['value' => $value ? 'true' : 'false', 'error' => null],
			default => $this->scalarOrList($value),
		};
	}

	/**
	 * An ISO 8601 datetime, read in the instance timezone when it carries no offset, stored
	 * as the naive minute in the instance timezone.
	 *
	 * @return array{value: ?string, error: ?string}
	 */
	private function datetime(mixed $value): array {
		if ($value === '') {
			return ['value' => null, 'error' => null];
		}
		if (!is_string($value) || preg_match(self::ISO_DATETIME_PATTERN, $value) !== 1) {
			return ['value' => null, 'error' => 'Expected an ISO 8601 datetime, such as 2026-10-16T14:30:00+02:00.'];
		}
		try {
			$dateTime = new DateTimeImmutable($value, $this->instanceTimezone->get());
		} catch (\Exception) {
			return ['value' => null, 'error' => 'Expected an ISO 8601 datetime, such as 2026-10-16T14:30:00+02:00.'];
		}
		return ['value' => $dateTime->setTimezone($this->instanceTimezone->get())->format('Y-m-d H:i'), 'error' => null];
	}

	/**
	 * A link as {title, url, providerId}, or a text the link column already accepts.
	 *
	 * @return array{value: ?string, error: ?string}
	 */
	private function link(mixed $value): array {
		if (is_string($value)) {
			return ['value' => $value, 'error' => null];
		}
		if (!is_array($value) || !isset($value['url']) || !is_string($value['url'])) {
			return ['value' => null, 'error' => 'Expected a link as {"title": ..., "url": ...} or a URL.'];
		}
		$link = [
			'title' => (string)($value['title'] ?? $value['url']),
			'value' => $value['url'],
			'providerId' => (string)($value['providerId'] ?? 'url'),
		];
		return ['value' => (string)json_encode($link), 'error' => null];
	}

	/**
	 * @return array{value: int|float|string|null, error: ?string}
	 */
	private function number(mixed $value): array {
		if ($value === '') {
			return ['value' => null, 'error' => null];
		}
		if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
			return ['value' => null, 'error' => 'Expected a number.'];
		}
		return ['value' => $value, 'error' => null];
	}

	/**
	 * A relation by the uuid of the target row, or by its row id or label, which the
	 * relation column resolves itself.
	 *
	 * @return array{value: int|string|null, error: ?string}
	 * @throws InternalError
	 */
	private function relation(mixed $value): array {
		if (is_string($value) && preg_match(self::UUID_PATTERN, $value) === 1) {
			$rowId = $this->row2Mapper->findIdByUuid(strtolower($value));
			return $rowId === null
				? ['value' => null, 'error' => 'There is no row with this uuid.']
				: ['value' => $rowId, 'error' => null];
		}
		if (!is_int($value) && !is_string($value)) {
			return ['value' => null, 'error' => 'Expected the uuid, row id or label of a row.'];
		}
		return ['value' => $value, 'error' => null];
	}

	/**
	 * A list of {id, type}, with the displayName the object shape returns left out.
	 *
	 * @return array{value: ?array, error: ?string}
	 */
	private function usergroups(mixed $value): array {
		if (!is_array($value) || !array_is_list($value)) {
			return ['value' => null, 'error' => 'Expected a list of {"id": ..., "type": ...}.'];
		}
		$entries = [];
		foreach ($value as $entry) {
			if (!is_array($entry) || !isset($entry['id'], $entry['type']) || !is_string($entry['id']) || !is_int($entry['type'])) {
				return ['value' => null, 'error' => 'Expected a list of {"id": ..., "type": ...}.'];
			}
			$entries[] = ['id' => $entry['id'], 'type' => $entry['type']];
		}
		return ['value' => $entries, 'error' => null];
	}

	/**
	 * @return array{value: array|string|int|float|null, error: ?string}
	 */
	private function scalarOrList(mixed $value): array {
		if (is_string($value) || is_int($value) || is_float($value)) {
			return ['value' => $value, 'error' => null];
		}
		if (is_array($value) && array_is_list($value)) {
			return ['value' => $value, 'error' => null];
		}
		return ['value' => null, 'error' => 'Unexpected value type for this column.'];
	}
}
