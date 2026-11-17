<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use DateTimeImmutable;
use Exception;
use OCA\Tables\Constants\FilterOperator;
use OCA\Tables\Db\Column;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Model\RowListQuery;

/**
 * Resolves the field names of a rows listing against the columns a user can see in a
 * table or view, and builds the filter and sort that Row2Mapper understands.
 *
 * Filters are a list of OR groups, each a list of filters that must all match. The view
 * filter always applies: the request filters and one search condition are added to each
 * of its OR groups, so a request can never widen the view.
 *
 * @psalm-type ResolvedFilter = array{columnId: int, operator: string, value: string, literal?: true}
 * @psalm-type ResolvedSortRule = array{columnId: int, mode: 'ASC'|'DESC'}
 * @psalm-type RowListCriteria = array{columnIds: list<int>, filter: list<list<ResolvedFilter>>, sort: list<ResolvedSortRule>, matchesNothing: bool}
 */
class RowQueryResolver {
	public const META_FIELDS = [
		'id' => Column::TYPE_META_ID,
		'createdBy' => Column::TYPE_META_CREATED_BY,
		'updatedBy' => Column::TYPE_META_UPDATED_BY,
		'createdAt' => Column::TYPE_META_CREATED_AT,
		'updatedAt' => Column::TYPE_META_UPDATED_AT,
	];
	private const RESERVED_FIELDS = ['uuid'];
	private const SUBTYPE_MULTI = 'multi';

	// the type a meta field holds, as a key of OPERATORS_BY_TYPE
	private const META_FIELD_TYPES = [
		Column::TYPE_META_ID => 'meta-id',
		Column::TYPE_META_CREATED_BY => 'meta-user',
		Column::TYPE_META_UPDATED_BY => 'meta-user',
		Column::TYPE_META_CREATED_AT => 'meta-datetime',
		Column::TYPE_META_UPDATED_AT => 'meta-datetime',
	];
	private const NUMBER_TYPES = ['number', 'number-stars', 'number-progress', 'meta-id'];
	private const DATE_TYPES = ['datetime', 'datetime-date', 'meta-datetime'];
	private const COMPARISONS = [
		FilterOperator::IS_EQUAL, FilterOperator::IS_NOT_EQUAL,
		FilterOperator::IS_GREATER_THAN, FilterOperator::IS_GREATER_THAN_OR_EQUAL,
		FilterOperator::IS_LESS_THAN, FilterOperator::IS_LESS_THAN_OR_EQUAL,
	];
	/**
	 * The operators per column type (type-subtype), as the view filter in the UI offers
	 * them (src/shared/components/ncTable/mixins/filter.js, goodFor), except contains and
	 * does-not-contain on relations, whose values are integers that LIKE cannot compare.
	 * A text column without a subtype is a line of text.
	 *
	 * @var array<string, list<FilterOperator>>
	 */
	private const OPERATORS_BY_TYPE = [
		'text' => [FilterOperator::CONTAINS, FilterOperator::DOES_NOT_CONTAIN, FilterOperator::BEGINS_WITH, FilterOperator::ENDS_WITH, FilterOperator::IS_EQUAL, FilterOperator::IS_NOT_EQUAL, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'text-line' => [FilterOperator::CONTAINS, FilterOperator::DOES_NOT_CONTAIN, FilterOperator::BEGINS_WITH, FilterOperator::ENDS_WITH, FilterOperator::IS_EQUAL, FilterOperator::IS_NOT_EQUAL, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'text-link' => [FilterOperator::CONTAINS, FilterOperator::DOES_NOT_CONTAIN, FilterOperator::BEGINS_WITH, FilterOperator::ENDS_WITH, FilterOperator::IS_EQUAL, FilterOperator::IS_NOT_EQUAL, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'text-long' => [FilterOperator::CONTAINS, FilterOperator::DOES_NOT_CONTAIN, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'text-rich' => [FilterOperator::CONTAINS, FilterOperator::DOES_NOT_CONTAIN, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'number' => [...self::COMPARISONS, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'number-stars' => [...self::COMPARISONS, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'number-progress' => [...self::COMPARISONS, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'datetime' => [...self::COMPARISONS, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'datetime-date' => [...self::COMPARISONS, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'datetime-time' => [...self::COMPARISONS, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'selection' => [FilterOperator::CONTAINS_ITEM, FilterOperator::CONTAINS, FilterOperator::DOES_NOT_CONTAIN, FilterOperator::IS_EQUAL, FilterOperator::IS_NOT_EQUAL, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'selection-multi' => [FilterOperator::CONTAINS_ITEM, FilterOperator::CONTAINS, FilterOperator::DOES_NOT_CONTAIN, FilterOperator::IS_EQUAL, FilterOperator::IS_NOT_EQUAL, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'selection-check' => [FilterOperator::IS_EQUAL, FilterOperator::IS_NOT_EQUAL, FilterOperator::IS_EMPTY],
		'usergroup' => [FilterOperator::CONTAINS_ITEM, FilterOperator::CONTAINS, FilterOperator::DOES_NOT_CONTAIN, FilterOperator::IS_EQUAL, FilterOperator::IS_NOT_EQUAL, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'relation' => [FilterOperator::IS_EQUAL, FilterOperator::IS_NOT_EQUAL, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'meta-id' => [...self::COMPARISONS, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'meta-user' => [FilterOperator::CONTAINS, FilterOperator::DOES_NOT_CONTAIN, FilterOperator::BEGINS_WITH, FilterOperator::ENDS_WITH, FilterOperator::IS_EQUAL, FilterOperator::IS_NOT_EQUAL, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
		'meta-datetime' => [...self::COMPARISONS, FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY],
	];

	/**
	 * @param Column[] $columns the columns the user can see, in display order
	 * @param array $viewFilter the filter of the view, or an empty array for a table
	 * @param list<ResolvedSortRule> $defaultSort the sort of the table or view
	 * @return RowListCriteria
	 * @throws BadRequestError
	 */
	public function resolve(RowListQuery $query, array $columns, array $viewFilter, array $defaultSort): array {
		$columnsByName = $this->indexColumnsByName($columns);

		$requestFilters = array_map(
			fn (array $filter): array => $this->resolveFilter($filter, $columnsByName),
			$query->filters,
		);
		$searchFilters = $query->search === null ? [null] : $this->buildSearchFilters($query->search, $columns);

		return [
			'columnIds' => $this->resolveFields($query->fields, $columns, $columnsByName),
			'filter' => $this->combineFilters($viewFilter, $requestFilters, $searchFilters),
			'sort' => $query->sort === [] ? $defaultSort : $this->resolveSort($query->sort, $columnsByName),
			'matchesNothing' => $searchFilters === [],
		];
	}

	/**
	 * @param Column[] $columns
	 * @return array<string, Column>
	 */
	private function indexColumnsByName(array $columns): array {
		$columnsByName = [];
		foreach ($columns as $column) {
			$columnsByName[(string)$column->getId()] = $column;
			$technicalName = $column->getTechnicalName();
			if ($technicalName !== null && $technicalName !== '' && !isset(self::META_FIELDS[$technicalName])) {
				$columnsByName[$technicalName] = $column;
			}
		}
		return $columnsByName;
	}

	/**
	 * Meta fields take precedence over technical names; a column whose technical name is a
	 * meta field name stays reachable by its column id.
	 *
	 * @param array<string, Column> $columnsByName
	 * @throws BadRequestError
	 */
	private function resolveColumnId(string $field, array $columnsByName): int {
		if (isset(self::META_FIELDS[$field])) {
			return self::META_FIELDS[$field];
		}
		if (in_array($field, self::RESERVED_FIELDS, true)) {
			throw new BadRequestError(sprintf('%s cannot be used to filter or sort yet.', $field));
		}
		if (!isset($columnsByName[$field])) {
			throw new BadRequestError(sprintf('%s is not a column of this table or view.', $field));
		}
		return $columnsByName[$field]->getId();
	}

	/**
	 * @param list<string>|null $fields
	 * @param Column[] $columns
	 * @param array<string, Column> $columnsByName
	 * @return list<int>
	 * @throws BadRequestError
	 */
	private function resolveFields(?array $fields, array $columns, array $columnsByName): array {
		$allColumnIds = array_values(array_map(static fn (Column $column): int => $column->getId(), $columns));
		if ($fields === null) {
			return $allColumnIds;
		}

		$wantedColumnIds = [];
		foreach ($fields as $field) {
			$columnId = $this->resolveColumnId($field, $columnsByName);
			// the metadata of a row is always returned
			if ($columnId > 0) {
				$wantedColumnIds[] = $columnId;
			}
		}
		return array_values(array_intersect($allColumnIds, $wantedColumnIds));
	}

	/**
	 * @param list<array{field: string, mode: 'ASC'|'DESC'}> $sort
	 * @param array<string, Column> $columnsByName
	 * @return list<ResolvedSortRule>
	 * @throws BadRequestError
	 */
	private function resolveSort(array $sort, array $columnsByName): array {
		return array_map(
			fn (array $sortRule): array => [
				'columnId' => $this->resolveColumnId($sortRule['field'], $columnsByName),
				'mode' => $sortRule['mode'],
			],
			$sort,
		);
	}

	/**
	 * @param array{field: string, operator: ?FilterOperator, value: string} $filter
	 * @param array<string, Column> $columnsByName
	 * @return ResolvedFilter
	 * @throws BadRequestError
	 */
	private function resolveFilter(array $filter, array $columnsByName): array {
		$columnId = $this->resolveColumnId($filter['field'], $columnsByName);
		$column = $columnId > 0 ? $columnsByName[(string)$columnId] : null;
		$operator = $filter['operator'] ?? $this->defaultOperator($column);

		$this->assertFilterFitsField($filter['field'], $column === null ? (self::META_FIELD_TYPES[$columnId] ?? '') : $this->columnTypeKey($column), $operator, $filter['value']);

		return ['columnId' => $columnId, 'operator' => $operator->value, 'value' => $filter['value'], 'literal' => true];
	}

	/**
	 * Without an operator a filter checks for equality, or on columns that hold several
	 * values, whether the value is one of them.
	 */
	private function defaultOperator(?Column $column): FilterOperator {
		if ($column !== null && $this->holdsMultipleValues($column)) {
			return FilterOperator::CONTAINS_ITEM;
		}
		return FilterOperator::IS_EQUAL;
	}

	private function holdsMultipleValues(Column $column): bool {
		return $column->getType() === Column::TYPE_USERGROUP
			|| ($column->getType() === Column::TYPE_SELECTION && $column->getSubtype() === self::SUBTYPE_MULTI);
	}

	private function columnTypeKey(Column $column): string {
		$subtype = (string)$column->getSubtype();
		return $column->getType() . ($subtype === '' ? '' : '-' . $subtype);
	}

	/**
	 * The operators the view filter offers for the field's type, and a value that type can hold.
	 *
	 * @throws BadRequestError
	 */
	private function assertFilterFitsField(string $field, string $typeKey, FilterOperator $operator, string $value): void {
		if (!in_array($operator, self::OPERATORS_BY_TYPE[$typeKey] ?? [], true)) {
			throw new BadRequestError(sprintf('%s does not support the %s operator.', $field, $operator->value));
		}
		if (in_array($operator, [FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY], true)) {
			return;
		}
		if (in_array($typeKey, self::NUMBER_TYPES, true) && !is_numeric($value)) {
			throw new BadRequestError(sprintf('%s can only be filtered by a number.', $field));
		}
		if ($typeKey === 'relation' && preg_match('/^\d+$/', $value) !== 1) {
			throw new BadRequestError(sprintf('%s can only be filtered by a row id.', $field));
		}
		if (in_array($typeKey, self::DATE_TYPES, true)) {
			try {
				new DateTimeImmutable($value);
			} catch (Exception) {
				throw new BadRequestError(sprintf('%s can only be filtered by a date.', $field));
			}
		}
	}

	/**
	 * One condition per searchable column, of which a row has to match at least one.
	 * An empty list means no column can match the search.
	 *
	 * @param Column[] $columns
	 * @return list<ResolvedFilter>
	 */
	private function buildSearchFilters(string $search, array $columns): array {
		$searchFilters = [];
		foreach ($columns as $column) {
			if ($column->getType() === Column::TYPE_TEXT) {
				$searchFilters[] = ['columnId' => $column->getId(), 'operator' => FilterOperator::CONTAINS->value, 'value' => $search, 'literal' => true];
				continue;
			}
			if ($column->getType() === Column::TYPE_SELECTION && $column->getSubtype() !== Column::SUBTYPE_SELECTION_CHECK) {
				array_push($searchFilters, ...$this->buildSelectionSearchFilters($search, $column));
			}
		}
		return $searchFilters;
	}

	/**
	 * Selection cells store option ids, so the search looks up the options whose label
	 * matches and filters on their ids.
	 *
	 * @return list<ResolvedFilter>
	 */
	private function buildSelectionSearchFilters(string $search, Column $column): array {
		$operator = $column->getSubtype() === self::SUBTYPE_MULTI ? FilterOperator::CONTAINS : FilterOperator::IS_EQUAL;
		$searchFilters = [];
		foreach ($column->getSelectionOptionsArray() ?? [] as $option) {
			if (mb_stripos((string)$option['label'], $search) !== false) {
				$searchFilters[] = ['columnId' => $column->getId(), 'operator' => $operator->value, 'value' => (string)$option['id'], 'literal' => true];
			}
		}
		return $searchFilters;
	}

	/**
	 * @param array $viewFilter
	 * @param list<ResolvedFilter> $requestFilters
	 * @param list<ResolvedFilter|null> $searchFilters null stands for no search
	 * @return list<list<ResolvedFilter>>
	 */
	private function combineFilters(array $viewFilter, array $requestFilters, array $searchFilters): array {
		$viewGroups = $viewFilter === [] ? [[]] : array_values($viewFilter);

		$combinedGroups = [];
		foreach ($viewGroups as $viewGroup) {
			foreach ($searchFilters as $searchFilter) {
				$group = [...array_values($viewGroup), ...$requestFilters];
				if ($searchFilter !== null) {
					$group[] = $searchFilter;
				}
				if ($group !== []) {
					$combinedGroups[] = $group;
				}
			}
		}
		return $combinedGroups;
	}
}
