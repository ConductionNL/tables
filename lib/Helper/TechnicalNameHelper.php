<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Helper;

/**
 * Makes technical names: lower case, letters, digits and underscores, starting
 * with a letter, unique within their scope.
 */
class TechnicalNameHelper {
	public const PATTERN = '/^[a-z][a-z0-9_]*$/';
	public const MAX_LENGTH = 200;
	public const START_PAGE_NAME = 'dashboard';

	/**
	 * A technical name made from free text. Every run of characters outside
	 * `[a-z0-9]` becomes one underscore; a name that does not start with a letter
	 * gets the prefix, and an empty one is the prefix itself.
	 */
	public static function fromText(string $text, string $prefix = 'page', int $maxLength = self::MAX_LENGTH): string {
		$name = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($text)) ?? '', '_');
		if ($name === '') {
			return $prefix;
		}
		if (!ctype_alpha($name[0])) {
			$name = $prefix . '_' . $name;
		}
		return rtrim(substr($name, 0, $maxLength), '_');
	}

	/**
	 * The base name, or the base with the lowest free suffix `_2`, `_3`, ...
	 *
	 * @param array<string, true> $taken
	 */
	public static function unique(string $base, array $taken, int $maxLength = self::MAX_LENGTH): string {
		$technicalName = $base;
		for ($suffix = 2; isset($taken[$technicalName]); $suffix++) {
			$ending = '_' . $suffix;
			$technicalName = rtrim(substr($base, 0, $maxLength - strlen($ending)), '_') . $ending;
		}
		return $technicalName;
	}

	/**
	 * Names a set of pages within one application. Pages whose given name is free
	 * keep it, in the order given; every other page gets a unique name made from its
	 * given name, else its fallback.
	 *
	 * @param array<array-key, array{name: ?string, fallback: string}> $pages
	 * @param array<string, true> $taken names already used in the application
	 * @return array<array-key, string> the name of every page, by its key
	 */
	public static function assign(array $pages, array $taken = []): array {
		$names = [];
		foreach ($pages as $key => $page) {
			$given = $page['name'];
			if ($given !== null && $given !== '' && self::isValid($given) && !isset($taken[$given])) {
				$names[$key] = $given;
				$taken[$given] = true;
			}
		}
		foreach ($pages as $key => $page) {
			if (isset($names[$key])) {
				continue;
			}
			$given = $page['name'];
			$base = $given !== null && $given !== '' ? self::fromText($given) : $page['fallback'];
			$names[$key] = self::unique($base, $taken);
			$taken[$names[$key]] = true;
		}
		$ordered = [];
		foreach (array_keys($pages) as $key) {
			$ordered[$key] = $names[$key];
		}
		return $ordered;
	}

	/**
	 * The name a page gets when none is given: `dashboard` for a start page, else
	 * one made from its title.
	 */
	public static function pageFallback(bool $isStartPage, ?string $title): string {
		if ($isStartPage) {
			return self::START_PAGE_NAME;
		}
		return self::fromText($title ?? '');
	}

	public static function isValid(string $technicalName, int $maxLength = self::MAX_LENGTH): bool {
		return strlen($technicalName) <= $maxLength
			&& preg_match(self::PATTERN, $technicalName) === 1;
	}
}
