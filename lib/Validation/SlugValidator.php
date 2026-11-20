<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Validation;

/**
 * The rule for the slugs of applications and of the tables and views in them:
 * kebab case, so lower case letters and digits in groups separated by single
 * dashes, starting with a letter, at most 200 characters. Slugs are not
 * technical names: those belong to columns and views and follow their own rule.
 * Callers report a broken rule in their own words.
 */
class SlugValidator {
	public const MAX_LENGTH = 200;
	/** The rule without delimiters, for route requirements */
	public const ROUTE_PATTERN = '[a-z][a-z0-9]*(?:-[a-z0-9]+)*';
	private const PATTERN = '/^' . self::ROUTE_PATTERN . '$/D';

	public static function isValid(string $slug): bool {
		return !self::isTooLong($slug) && self::hasValidCharacters($slug);
	}

	public static function isTooLong(string $slug): bool {
		return strlen($slug) > self::MAX_LENGTH;
	}

	public static function hasValidCharacters(string $slug): bool {
		return preg_match(self::PATTERN, $slug) === 1;
	}
}
