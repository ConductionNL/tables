<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use OCA\Tables\ResponseDefinitions;

/**
 * Turns the object shape of api/2, as RowObjectSerializer gives it for JSON, into data
 * the OCS XML writer renders as one element per property:
 *
 * - the metadata in `@self` becomes `<self/>` with a member per attribute, an attribute
 *   whose value is null is left out;
 * - a property becomes an element named by its key; a key that is not an ASCII XML name
 *   (a letter or `_`, then letters, digits, `_`, `.` or `-`), or that is `element` (or
 *   `self` for a row), becomes `<element name="KEY">`, with the value in `<value>` when
 *   it is not a list or an object, because an OCS element cannot have attributes and
 *   text; so under the fallback a scalar `"x"` and an object `{"value": "x"}` render
 *   the same, which no column type runs into, because none gives an object with a
 *   `value` property;
 * - null becomes an empty element with `nil="true"`, unprefixed because an undeclared
 *   prefix such as `xsi:` is not namespace-well-formed; an empty string or an empty list
 *   becomes an empty element without it;
 * - booleans become `true` and `false`, numbers are written as JSON writes them;
 * - text, attribute values and the name in `<element name="KEY">` hold only characters
 *   XML 1.0 allows: invalid UTF-8 and other characters, such as control characters,
 *   become U+FFFD, because XMLWriter writes them as they are and the document would not
 *   be well-formed;
 * - a list becomes `<element>` children, an object child elements by key, both by the
 *   same rules.
 *
 * @psalm-import-type TablesRowObject from ResponseDefinitions
 * @psalm-import-type TablesRowValidationError from ResponseDefinitions
 */
class RowObjectXmlShaper {
	/**
	 * ASCII only: XMLWriter, which renders OCS XML, refuses some names that Unicode counts
	 * as letters (such as `µ` or `ǅ`), which would make a 500 after the controller returned.
	 * `D` keeps `$` from matching before a trailing newline.
	 */
	private const SAFE_NAME = '/^[A-Za-z_][A-Za-z0-9_.\-]*$/D';
	/** A character XML 1.0 does not allow. */
	private const NOT_XML_CHAR = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';
	private const REPLACEMENT_CHARACTER = "\u{FFFD}";
	private const LIST_ITEM = 'element';
	private const SELF = 'self';

	/**
	 * @param list<TablesRowObject> $objects
	 * @return list<array<array-key, mixed>>
	 */
	public function shapeRows(array $objects): array {
		return array_map($this->shapeRow(...), $objects);
	}

	/**
	 * @param TablesRowObject $object
	 * @return array<array-key, mixed>
	 */
	public function shapeRow(array $object): array {
		$shaped = [];
		foreach ($object as $key => $value) {
			if ($key === '@self' && is_array($value)) {
				$shaped[self::SELF] = ['@attributes' => $this->attributes($value)];
				continue;
			}
			$this->addProperty($shaped, $key, $value, [self::LIST_ITEM, self::SELF]);
		}
		return $shaped;
	}

	/**
	 * @param TablesRowValidationError $error
	 * @return array{message: string, errors: array<array-key, mixed>}
	 */
	public function shapeValidationError(array $error): array {
		return [
			'message' => $error['message'],
			'errors' => $this->shapeObject($error['errors']),
		];
	}

	/**
	 * @param array<array-key, mixed> $members
	 * @return array<string, string>
	 */
	private function attributes(array $members): array {
		$attributes = [];
		foreach ($members as $name => $value) {
			if (is_scalar($value)) {
				$attributes[(string)$name] = $this->text($value);
			}
		}
		return $attributes;
	}

	/**
	 * @param array<array-key, mixed> $object
	 * @return array<array-key, mixed>
	 */
	private function shapeObject(array $object): array {
		$shaped = [];
		foreach ($object as $key => $value) {
			$this->addProperty($shaped, $key, $value, [self::LIST_ITEM]);
		}
		return $shaped;
	}

	/**
	 * @param array<array-key, mixed> $shaped
	 * @param list<string> $reservedNames names that also take the fallback element
	 */
	private function addProperty(array &$shaped, int|string $key, mixed $value, array $reservedNames): void {
		$name = (string)$key;
		if (preg_match(self::SAFE_NAME, $name) === 1 && !in_array($name, $reservedNames, true)) {
			$shaped[$name] = $this->shapeValue($value);
			return;
		}
		$fallback = ['@attributes' => ['name' => $this->xmlString($name)]];
		if (is_array($value)) {
			$shaped[] = $fallback + $this->shapeArray($value);
			return;
		}
		$fallback['value'] = $this->shapeValue($value);
		$shaped[] = $fallback;
	}

	/**
	 * @return string|array<array-key, mixed>
	 */
	private function shapeValue(mixed $value): string|array {
		if ($value === null) {
			return ['@attributes' => ['nil' => 'true']];
		}
		return is_array($value) ? $this->shapeArray($value) : $this->text($value);
	}

	/**
	 * @param array<array-key, mixed> $value
	 * @return array<array-key, mixed>
	 */
	private function shapeArray(array $value): array {
		return array_is_list($value) ? array_map($this->shapeValue(...), $value) : $this->shapeObject($value);
	}

	private function text(mixed $value): string {
		return match (true) {
			is_bool($value) => $value ? 'true' : 'false',
			is_float($value) => json_encode($value, JSON_THROW_ON_ERROR),
			is_scalar($value) => $this->xmlString((string)$value),
			default => json_encode($value, JSON_THROW_ON_ERROR),
		};
	}

	private function xmlString(string $value): string {
		if (!mb_check_encoding($value, 'UTF-8')) {
			$substitute = mb_substitute_character();
			mb_substitute_character(0xFFFD);
			try {
				$value = mb_scrub($value, 'UTF-8');
			} finally {
				mb_substitute_character($substitute);
			}
		}
		return preg_replace(self::NOT_XML_CHAR, self::REPLACEMENT_CHARACTER, $value) ?? $value;
	}
}
