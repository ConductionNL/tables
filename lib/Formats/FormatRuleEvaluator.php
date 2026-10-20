<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Formats;

use OCA\Tables\Errors\FormatRuleError;
use OCA\Tables\Vendor\JWadhams\JsonLogic;
use Throwable;

/**
 * Evaluates format rules against a value, which rules read as {"var": "value"}.
 *
 * Rules are JSON Logic with three extra operations, match, chars and indexOf. substr, === and !==
 * are replaced so that they give the same results as src/shared/utils/formatRules.js: strings are
 * sequences of Unicode code points and an integer equals the same float. Both evaluators run the
 * vectors in tests/formats/format-vectors.json.
 */
class FormatRuleEvaluator {
	private const PATTERN_DELIMITER = "\x01";
	private const ALLOWED_PATTERN_FLAGS = '/^(?!.*(.).*\1)[ims]*$/';

	private static bool $operationsRegistered = false;

	/**
	 * @param array<string, mixed> $rule
	 * @throws FormatRuleError
	 */
	public function evaluate(array $rule, mixed $value): bool {
		return JsonLogic::truthy($this->apply($rule, $value));
	}

	/**
	 * Returns what the rule evaluates to, without reducing it to a boolean.
	 *
	 * @param array<string, mixed> $rule
	 * @throws FormatRuleError
	 */
	public function apply(array $rule, mixed $value): mixed {
		// registered here rather than in a constructor: the server may hand out a lazy instance whose
		// constructor only runs once a property is read, and this class has none
		self::registerOperations();
		try {
			return JsonLogic::apply($rule, ['value' => $value]);
		} catch (FormatRuleError $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new FormatRuleError('The format rule cannot be evaluated: ' . $e->getMessage(), 0, $e);
		}
	}

	private static function registerOperations(): void {
		if (self::$operationsRegistered) {
			return;
		}
		JsonLogic::add_operation('match', self::matchPattern(...));
		JsonLogic::add_operation('chars', self::chars(...));
		JsonLogic::add_operation('indexOf', self::indexOf(...));
		JsonLogic::add_operation('substr', self::substr(...));
		JsonLogic::add_operation('===', self::strictEquals(...));
		JsonLogic::add_operation('!==', static fn (mixed $a, mixed $b): bool => !self::strictEquals($a, $b));
		self::$operationsRegistered = true;
	}

	/**
	 * Whether a string value matches an unanchored pattern. $ only matches at the very end, as in
	 * JavaScript, unless the m flag is given. Values that are not strings never match.
	 *
	 * @throws FormatRuleError
	 */
	private static function matchPattern(mixed $value, mixed $pattern, mixed $flags = ''): bool {
		if (!is_string($pattern) || str_contains($pattern, self::PATTERN_DELIMITER)) {
			throw new FormatRuleError('match expects a pattern string');
		}
		if (!is_string($flags) || preg_match(self::ALLOWED_PATTERN_FLAGS, $flags) !== 1) {
			throw new FormatRuleError('match only accepts the flags i, m and s, each at most once');
		}
		if (!is_string($value)) {
			return false;
		}

		$result = @preg_match(self::PATTERN_DELIMITER . '(*UTF)' . $pattern . self::PATTERN_DELIMITER . 'D' . $flags, $value);
		if ($result === false) {
			if (preg_last_error() === PREG_BAD_UTF8_ERROR) {
				return false;
			}
			throw new FormatRuleError('The pattern ' . $pattern . ' cannot be used: ' . preg_last_error_msg());
		}
		return $result === 1;
	}

	/**
	 * @return list<string>
	 */
	private static function chars(mixed $value): array {
		return is_string($value) ? mb_str_split($value, 1, 'UTF-8') : [];
	}

	/**
	 * Position of the needle in a string (counted in code points) or in a list, or -1.
	 */
	private static function indexOf(mixed $haystack, mixed $needle): int {
		if (is_string($haystack)) {
			if (!is_string($needle)) {
				return -1;
			}
			$position = mb_strpos($haystack, $needle, 0, 'UTF-8');
			return $position === false ? -1 : $position;
		}
		if (is_array($haystack)) {
			foreach (array_values($haystack) as $index => $item) {
				if (self::strictEquals($item, $needle)) {
					return $index;
				}
			}
		}
		return -1;
	}

	/**
	 * A negative start counts from the end; a negative length leaves that many code points off the end.
	 * Sources other than strings and numbers count as an empty string.
	 */
	private static function substr(mixed $source, mixed $start = 0, mixed $length = null): string {
		$string = is_string($source) || is_int($source) || is_float($source) ? (string)$source : '';
		return mb_substr($string, (int)$start, $length === null ? null : (int)$length, 'UTF-8');
	}

	private static function strictEquals(mixed $a, mixed $b): bool {
		if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
			return $a == $b;
		}
		return $a === $b;
	}
}
