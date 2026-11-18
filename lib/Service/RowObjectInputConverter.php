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
 * column types already parse, reversing RowObjectSerializer. It reports every property it
 * cannot convert instead of dropping it. Whether a value is the one a cell is read as,
 * which is then not written again, is told by isReadAs(), so that a value read from a cell
 * that this class would refuse, such as a datetime with seconds, can be written back.
 */
class RowObjectInputConverter {
	private const SELF_KEY = '@self';
	private const ISO_DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/';
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
	/** the subtype multi-selections are stored with, which Column::SUBTYPE_SELECTION_MULTI does not match */
	private const SUBTYPE_SELECTION_MULTI = 'multi';

	public function __construct(
		private readonly Row2Mapper $row2Mapper,
		private readonly InstanceTimezone $instanceTimezone,
		private readonly RowObjectSerializer $serializer,
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
	 * Whether a value given in the object shape is the value the stored cell is read as,
	 * also when it is spelled differently: a datetime with another offset for the same
	 * instant, a number as a string, a checkbox as a boolean, a link without the parts the
	 * reader fills in, a usergroup without display names, or a relation by row id.
	 *
	 * @throws InternalError
	 */
	public function isReadAs(Column $column, mixed $given, mixed $stored): bool {
		$read = $this->serializer->readValue($column, $stored);
		if ($column->getType() === Column::TYPE_RELATION && $read !== null && !self::isEmpty($given)) {
			if (is_int($given) || (is_string($given) && ctype_digit($given))) {
				return (int)$given === (int)$stored;
			}
			return is_string($given) && strtolower($given) === $read;
		}
		return $this->comparable($column, $given) === $this->comparable($column, $read);
	}

	private static function isEmpty(mixed $value): bool {
		return $value === null || $value === '' || $value === [];
	}

	/**
	 * A value of the object shape in a form in which two spellings of the same value are
	 * identical.
	 */
	private function comparable(Column $column, mixed $value): mixed {
		if (self::isEmpty($value)) {
			return null;
		}
		if ($this->isPlainDatetime($column) && is_string($value) && preg_match(self::ISO_DATETIME_PATTERN, $value) === 1) {
			try {
				return (new DateTimeImmutable($value, $this->instanceTimezone->get()))->format('U.u');
			} catch (\Exception) {
				return $value;
			}
		}
		if ($column->getType() === Column::TYPE_TEXT && $column->getSubtype() === Column::SUBTYPE_TEXT_LINK
			&& is_array($value) && isset($value['url']) && is_string($value['url'])) {
			return [
				'title' => self::comparableScalars($value['title'] ?? $value['url']),
				'url' => $value['url'],
				'providerId' => self::comparableScalars($value['providerId'] ?? 'url'),
			];
		}
		if ($column->getType() === Column::TYPE_NUMBER && is_numeric($value)) {
			return (float)$value;
		}
		if ($column->getType() === Column::TYPE_SELECTION && $column->getSubtype() === Column::SUBTYPE_SELECTION_CHECK && is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if ($column->getType() === Column::TYPE_USERGROUP && is_array($value)) {
			return array_map(
				static fn (mixed $entry): mixed => is_array($entry) ? [self::comparableScalars($entry['id'] ?? null), self::comparableScalars($entry['type'] ?? null)] : self::comparableScalars($entry),
				$value,
			);
		}
		return self::comparableScalars($value);
	}

	/**
	 * Numbers and strings as strings, also inside arrays, since a cell can be read as either.
	 */
	private static function comparableScalars(mixed $value): mixed {
		if (is_int($value) || is_float($value)) {
			return (string)$value;
		}
		if (is_array($value)) {
			return array_map(self::comparableScalars(...), $value);
		}
		return $value;
	}

	private function isPlainDatetime(Column $column): bool {
		return $column->getType() === Column::TYPE_DATETIME
			&& !in_array($column->getSubtype(), [Column::SUBTYPE_DATETIME_DATE, Column::SUBTYPE_DATETIME_TIME], true);
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
			$this->isPlainDatetime($column) => $this->datetime($value),
			$column->getType() === Column::TYPE_TEXT && $column->getSubtype() === Column::SUBTYPE_TEXT_LINK => $this->link($value),
			$column->getType() === Column::TYPE_NUMBER => $this->number($value),
			$column->getType() === Column::TYPE_RELATION => $this->relation($value),
			$column->getType() === Column::TYPE_USERGROUP => $this->usergroups($value),
			$column->getType() === Column::TYPE_SELECTION && $column->getSubtype() === Column::SUBTYPE_SELECTION_CHECK => $this->check($value),
			$column->getType() === Column::TYPE_SELECTION && $column->getSubtype() === self::SUBTYPE_SELECTION_MULTI => $this->selectionList($value),
			default => $this->scalar($value),
		};
	}

	/**
	 * An ISO 8601 datetime, read in the instance timezone when it carries no offset, stored
	 * as the naive minute in the instance timezone. Seconds other than 00 are refused, since
	 * they cannot be stored.
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
		// a day or time that does not exist, such as February 30, is rolled over with a warning
		$parseErrors = DateTimeImmutable::getLastErrors();
		if ($parseErrors !== false && $parseErrors['warning_count'] > 0) {
			return ['value' => null, 'error' => 'Expected an ISO 8601 datetime, such as 2026-10-16T14:30:00+02:00.'];
		}
		// a wall time in the gap of a daylight saving change does not exist and is moved by PHP;
		// with an offset PHP keeps the given wall time, so this only refuses times without one
		if ($dateTime->format('Y-m-d H:i') !== str_replace('T', ' ', substr($value, 0, 16))) {
			return ['value' => null, 'error' => 'This time does not exist in the timezone of the instance, give it with an offset.'];
		}
		if ($dateTime->format('s.u') !== '00.000000') {
			return ['value' => null, 'error' => 'Expected a datetime to the minute, with 00 seconds, such as 2026-10-16T14:30:00+02:00.'];
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
		if (!is_array($value) || !isset($value['url']) || !is_string($value['url'])
			|| (isset($value['title']) && !is_string($value['title']))
			|| (isset($value['providerId']) && !is_string($value['providerId']))) {
			return ['value' => null, 'error' => 'Expected a link as {"title": ..., "url": ...} or a URL.'];
		}
		$link = [
			'title' => $value['title'] ?? $value['url'],
			'value' => $value['url'],
			'providerId' => $value['providerId'] ?? 'url',
		];
		return ['value' => (string)json_encode($link), 'error' => null];
	}

	/**
	 * A number, or a numeric string, which is passed on as a number.
	 *
	 * @return array{value: int|float|null, error: ?string}
	 */
	private function number(mixed $value): array {
		if ($value === '') {
			return ['value' => null, 'error' => null];
		}
		if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
			return ['value' => null, 'error' => 'Expected a number.'];
		}
		return ['value' => is_string($value) ? (float)$value : $value, 'error' => null];
	}

	/**
	 * A relation by the uuid of the target row, or by its row id or label, which the
	 * relation column resolves itself. An unknown uuid gets the message the relation column
	 * gives for a row outside its target, so that it does not tell whether a row exists.
	 *
	 * @return array{value: int|string|null, error: ?string}
	 * @throws InternalError
	 */
	private function relation(mixed $value): array {
		if (is_string($value) && preg_match(self::UUID_PATTERN, $value) === 1) {
			$rowId = $this->row2Mapper->findIdByUuid(strtolower($value));
			return $rowId === null
				? ['value' => null, 'error' => 'Relation value does not exist in the target table/view']
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
	 * A checkbox as true or false, or as the strings "true" and "false" it is stored as.
	 *
	 * @return array{value: ?string, error: ?string}
	 */
	private function check(mixed $value): array {
		if (is_bool($value)) {
			return ['value' => $value ? 'true' : 'false', 'error' => null];
		}
		if ($value === 'true' || $value === 'false') {
			return ['value' => $value, 'error' => null];
		}
		return ['value' => null, 'error' => 'Expected true or false.'];
	}

	/**
	 * A multi-selection as the list of its option ids, as it is read.
	 *
	 * @return array{value: list<int>|null, error: ?string}
	 */
	private function selectionList(mixed $value): array {
		if (!is_array($value) || !array_is_list($value)) {
			return ['value' => null, 'error' => 'Expected a list of option ids.'];
		}
		$optionIds = [];
		foreach ($value as $optionId) {
			if (!is_int($optionId)) {
				return ['value' => null, 'error' => 'Expected a list of option ids.'];
			}
			$optionIds[] = $optionId;
		}
		return ['value' => $optionIds, 'error' => null];
	}

	/**
	 * @return array{value: string|int|float|null, error: ?string}
	 */
	private function scalar(mixed $value): array {
		if (is_string($value) || is_int($value) || is_float($value)) {
			return ['value' => $value, 'error' => null];
		}
		return ['value' => null, 'error' => 'Unexpected value type for this column.'];
	}
}
