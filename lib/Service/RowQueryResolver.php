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
 * @psalm-type ResolvedFilter = array{columnId: int, operator: string, value: string}
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
	private const META_DATE_FIELDS = [Column::TYPE_META_CREATED_AT, Column::TYPE_META_UPDATED_AT];
	private const RESERVED_FIELDS = ['uuid'];
	private const SUBTYPE_MULTI = 'multi';

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

		if ($column === null) {
			$this->assertValidMetaFilter($filter['field'], $columnId, $operator, $filter['value']);
		}

		return ['columnId' => $columnId, 'operator' => $operator->value, 'value' => $filter['value']];
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

	/**
	 * @throws BadRequestError
	 */
	private function assertValidMetaFilter(string $field, int $columnId, FilterOperator $operator, string $value): void {
		if ($operator === FilterOperator::CONTAINS_ITEM) {
			throw new BadRequestError(sprintf('%s does not support the contains-item operator.', $field));
		}
		if (in_array($operator, [FilterOperator::IS_EMPTY, FilterOperator::IS_NOT_EMPTY], true)) {
			return;
		}
		if ($columnId === Column::TYPE_META_ID && preg_match('/^\d+$/', $value) !== 1) {
			throw new BadRequestError('id can only be filtered by an integer.');
		}
		if (in_array($columnId, self::META_DATE_FIELDS, true)) {
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
				$searchFilters[] = ['columnId' => $column->getId(), 'operator' => FilterOperator::CONTAINS->value, 'value' => $search];
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
				$searchFilters[] = ['columnId' => $column->getId(), 'operator' => $operator->value, 'value' => (string)$option['id']];
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
