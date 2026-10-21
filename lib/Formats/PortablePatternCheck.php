<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Formats;

/**
 * Finds what in a pattern only PCRE understands, or understands differently from JavaScript.
 *
 * Browsers compile patterns as `new RegExp(pattern, 'u')`, which accepts far less than PCRE. The
 * server checks every pattern it stores against that dialect, as imports through occ, JSON Schema
 * or manifests have no browser to try them in. Only constructs both engines read the same way are
 * let through; PCRE then still has to compile the pattern.
 */
class PortablePatternCheck {
	/** Escapes with the same meaning in both engines, outside and inside a character class */
	private const PORTABLE_ESCAPES = ['d', 'D', 'w', 'W', 's', 'S', 't', 'n', 'f', 'r'];
	/** Characters that only mean themselves once escaped */
	private const SYNTAX_CHARACTERS = '^$\\.*+?()[]{}|/';
	/** The groups JavaScript knows besides named ones */
	private const PORTABLE_GROUP_OPENERS = ['(?:', '(?=', '(?!', '(?<=', '(?<!'];

	/**
	 * A description of the first construct JavaScript cannot run the same way, or null.
	 */
	public static function findIncompatibility(string $pattern): ?string {
		$characters = mb_str_split($pattern, 1, 'UTF-8');
		$count = count($characters);
		$inClass = false;
		$classStart = 0;
		$previousWasQuantifier = false;

		for ($i = 0; $i < $count; $i++) {
			$character = $characters[$i];
			if ($character === '\\') {
				$problem = self::checkEscape($characters, $i, $inClass);
				if ($problem !== null) {
					return $problem;
				}
				$i += self::escapeLength($characters, $i) - 1;
				$previousWasQuantifier = false;
				continue;
			}

			if ($inClass) {
				if ($character === '[' && ($characters[$i + 1] ?? '') === ':') {
					return 'POSIX character classes such as [:alpha:]';
				}
				if ($character === ']' && $i > $classStart) {
					$inClass = false;
				}
				continue;
			}

			if ($character === '[') {
				$classStart = ($characters[$i + 1] ?? '') === '^' ? $i + 2 : $i + 1;
				if (($characters[$classStart] ?? '') === ']') {
					return 'an empty character class, or ] as its first character';
				}
				$inClass = true;
				$previousWasQuantifier = false;
				continue;
			}
			if ($character === '(') {
				$problem = self::checkGroup($characters, $i);
				if ($problem !== null) {
					return $problem;
				}
				$previousWasQuantifier = false;
				continue;
			}
			if (in_array($character, ['*', '+', '?'], true)) {
				if ($previousWasQuantifier && $character === '+') {
					return 'possessive quantifiers such as a++';
				}
				// a ? after a quantifier makes it lazy, which both engines know
				$previousWasQuantifier = !($previousWasQuantifier && $character === '?');
				continue;
			}
			if ($character === '{') {
				$length = self::quantifierLength($characters, $i);
				if ($length === 0) {
					return 'a { that does not start a quantifier such as {2,4}; escape it as \\{';
				}
				$i += $length - 1;
				$previousWasQuantifier = true;
				continue;
			}
			if ($character === '}' || $character === ']') {
				return 'an unescaped ' . $character . '; escape it as \\' . $character;
			}
			$previousWasQuantifier = false;
		}
		return $inClass ? 'a character class that is not closed' : null;
	}

	/**
	 * @param list<string> $characters
	 */
	private static function checkEscape(array $characters, int $i, bool $inClass): ?string {
		$next = $characters[$i + 1] ?? '';
		if ($next === '') {
			return 'a \\ at the end of the pattern';
		}
		if (in_array($next, self::PORTABLE_ESCAPES, true) || str_contains(self::SYNTAX_CHARACTERS, $next)) {
			return null;
		}
		if ($next === '-' && $inClass) {
			return null;
		}
		// \b is a word boundary outside a class and a backspace inside one, in both engines
		if ($next === 'b' || ($next === 'B' && !$inClass)) {
			return null;
		}
		if ($next === 'c' && preg_match('/^[A-Za-z]$/', $characters[$i + 2] ?? '') === 1) {
			return null;
		}
		if ($next === '0' && preg_match('/^[0-9]$/', $characters[$i + 2] ?? '') !== 1) {
			return null;
		}
		if (!$inClass && preg_match('/^[1-9]$/', $next) === 1) {
			return null;
		}
		if ($next === 'x') {
			return self::followedBy($characters, $i + 2, '/^[0-9A-Fa-f]{2}/') ? null : '\\x{…} or \\x without two hexadecimal digits; write the character itself';
		}
		if ($next === 'u') {
			return '\\u, which only JavaScript knows; write the character itself';
		}
		if ($next === 'p' || $next === 'P') {
			return self::followedBy($characters, $i + 2, '/^\{[A-Za-z_=]+\}/') ? null : '\\' . $next . ' without {…}, such as \\' . $next . 'L';
		}
		if ($next === 'k' && !$inClass) {
			return self::followedBy($characters, $i + 2, '/^<[A-Za-z_][A-Za-z0-9_]*>/') ? null : '\\k without <name>';
		}
		return 'the escape \\' . $next . ', which only PCRE knows or reads differently';
	}

	/**
	 * How many characters the escape starting at $i takes, including the backslash.
	 *
	 * @param list<string> $characters
	 */
	private static function escapeLength(array $characters, int $i): int {
		$next = $characters[$i + 1] ?? '';
		$rest = implode('', array_slice($characters, $i + 2, 12));
		return match (true) {
			$next === 'c' => 3,
			$next === 'x' => 4,
			($next === 'p' || $next === 'P') && preg_match('/^\{[A-Za-z_=]+\}/', $rest, $match) === 1 => 2 + strlen($match[0]),
			$next === 'k' && preg_match('/^<[A-Za-z_][A-Za-z0-9_]*>/', $rest, $match) === 1 => 2 + strlen($match[0]),
			default => 2,
		};
	}

	/**
	 * @param list<string> $characters
	 */
	private static function checkGroup(array $characters, int $i): ?string {
		$next = $characters[$i + 1] ?? '';
		if ($next === '*') {
			return 'verbs such as (*UCP) or (*SKIP)';
		}
		if ($next !== '?') {
			return null;
		}
		$opener = implode('', array_slice($characters, $i, 4));
		foreach (self::PORTABLE_GROUP_OPENERS as $portable) {
			if (str_starts_with($opener, $portable)) {
				return null;
			}
		}
		if (self::followedBy($characters, $i + 2, '/^<[A-Za-z_][A-Za-z0-9_]*>/')) {
			return null;
		}
		return match ($characters[$i + 2] ?? '') {
			'>' => 'atomic groups such as (?>…)',
			'P' => 'Python-style named groups such as (?P<name>…); use (?<name>…)',
			'\'' => 'named groups in quotes; use (?<name>…)',
			'#' => 'comments such as (?#…)',
			'|' => 'branch reset groups such as (?|…)',
			'(' => 'conditional groups such as (?(1)…)',
			'R', '&', '+', '-', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' => 'recursion and subroutine calls such as (?R)',
			default => 'inline flags such as (?i); use the flags argument of match',
		};
	}

	/**
	 * The length of a quantifier {n}, {n,} or {n,m} starting at $i, or 0 when there is none.
	 *
	 * @param list<string> $characters
	 */
	private static function quantifierLength(array $characters, int $i): int {
		$rest = implode('', array_slice($characters, $i, 24));
		return preg_match('/^\{[0-9]+(?:,[0-9]*)?\}/', $rest, $match) === 1 ? strlen($match[0]) : 0;
	}

	/**
	 * @param list<string> $characters
	 */
	private static function followedBy(array $characters, int $from, string $pattern): bool {
		return preg_match($pattern, implode('', array_slice($characters, $from, 12))) === 1;
	}
}
