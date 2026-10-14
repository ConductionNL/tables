<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Model;

use InvalidArgumentException;
use OCA\Tables\Constants\FilterOperator;

/**
 * The query parameters of a rows listing, validated but not yet resolved against the
 * columns of a table or view. Fields are still the names the client used.
 */
final class RowListQuery {
	public const DEFAULT_LIMIT = 25;

	/**
	 * @param list<array{field: string, mode: 'ASC'|'DESC'}> $sort
	 * @param list<string>|null $fields
	 * @param list<array{field: string, operator: ?FilterOperator, value: string}> $filters
	 */
	public function __construct(
		public readonly int $limit,
		public readonly int $offset,
		public readonly array $sort,
		public readonly ?string $search,
		public readonly ?array $fields,
		public readonly array $filters,
	) {
	}

	/**
	 * The page the offset falls in, so an offset that is not a multiple of the limit
	 * reports the page its first row is on.
	 */
	public function getPage(): int {
		return intdiv($this->offset, $this->limit) + 1;
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public static function createFromRequest(
		?string $page,
		?string $offset,
		?string $limit,
		?string $sort,
		?string $search,
		?string $fields,
		mixed $filter,
	): self {
		if ($page !== null && $offset !== null) {
			throw new InvalidArgumentException('Use either page or offset, not both.');
		}
		$parsedLimit = $limit === null ? self::DEFAULT_LIMIT : self::parseInteger('limit', $limit, 1);
		$parsedOffset = $offset === null ? 0 : self::parseInteger('offset', $offset, 0);
		if ($page !== null) {
			$parsedOffset = (self::parseInteger('page', $page, 1) - 1) * $parsedLimit;
		}

		$trimmedSearch = $search === null ? '' : trim($search);

		return new self(
			$parsedLimit,
			$parsedOffset,
			$sort === null ? [] : self::parseSort($sort),
			$trimmedSearch === '' ? null : $trimmedSearch,
			$fields === null ? null : self::parseList('fields', $fields),
			$filter === null ? [] : self::parseFilters($filter),
		);
	}

	/**
	 * @throws InvalidArgumentException
	 */
	private static function parseInteger(string $name, string $value, int $minimum): int {
		if (preg_match('/^\d+$/', $value) !== 1 || (int)$value < $minimum) {
			throw new InvalidArgumentException(sprintf('%s must be an integer of at least %d.', $name, $minimum));
		}
		return (int)$value;
	}

	/**
	 * @return list<array{field: string, mode: 'ASC'|'DESC'}>
	 * @throws InvalidArgumentException
	 */
	private static function parseSort(string $sort): array {
		$sortRules = [];
		foreach (self::parseList('sort', $sort) as $sortField) {
			$isDescending = str_starts_with($sortField, '-');
			$field = $isDescending ? substr($sortField, 1) : $sortField;
			if ($field === '') {
				throw new InvalidArgumentException('sort contains a minus without a field.');
			}
			$sortRules[] = ['field' => $field, 'mode' => $isDescending ? 'DESC' : 'ASC'];
		}
		return $sortRules;
	}

	/**
	 * @return list<string>
	 * @throws InvalidArgumentException
	 */
	private static function parseList(string $name, string $value): array {
		$items = array_map('trim', explode(',', $value));
		if (in_array('', $items, true)) {
			throw new InvalidArgumentException(sprintf('%s contains an empty entry.', $name));
		}
		return $items;
	}

	/**
	 * Accepts filter[field]=value and filter[field][operator]=value.
	 *
	 * @return list<array{field: string, operator: ?FilterOperator, value: string}>
	 * @throws InvalidArgumentException
	 */
	private static function parseFilters(mixed $filter): array {
		if (!is_array($filter)) {
			throw new InvalidArgumentException('filter must be given as filter[field] or filter[field][operator].');
		}
		$filters = [];
		foreach ($filter as $field => $condition) {
			$field = (string)$field;
			if (is_string($condition)) {
				$filters[] = ['field' => $field, 'operator' => null, 'value' => $condition];
				continue;
			}
			if (!is_array($condition) || $condition === []) {
				throw new InvalidArgumentException(sprintf('The filter on %s has no value.', $field));
			}
			foreach ($condition as $operatorName => $value) {
				$operator = FilterOperator::tryFrom((string)$operatorName);
				if ($operator === null) {
					throw new InvalidArgumentException(sprintf('%s is not a filter operator.', $operatorName));
				}
				if (!is_string($value)) {
					throw new InvalidArgumentException(sprintf('The filter on %s has an invalid value.', $field));
				}
				$filters[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
			}
		}
		return $filters;
	}
}
