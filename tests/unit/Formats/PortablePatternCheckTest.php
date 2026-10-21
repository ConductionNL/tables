<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Formats;

use OCA\Tables\Errors\FormatRuleError;
use OCA\Tables\Formats\FormatRuleEvaluator;
use OCA\Tables\Formats\PortablePatternCheck;
use PHPUnit\Framework\TestCase;

/**
 * The portable patterns are compiled by tests/frontend/patterns.test.mjs as well.
 */
class PortablePatternCheckTest extends TestCase {
	private const VECTORS_FILE = __DIR__ . '/../../formats/pattern-vectors.json';
	private const CORE_FORMATS_FILE = __DIR__ . '/../../../lib/Formats/core-formats.json';

	/**
	 * @dataProvider portablePatterns
	 */
	public function testAcceptsAPatternBothEnginesReadTheSame(string $pattern): void {
		$this->assertNull(PortablePatternCheck::findIncompatibility($pattern));
		(new FormatRuleEvaluator())->assertPortablePattern($pattern);
	}

	/**
	 * @dataProvider refusedPatterns
	 */
	public function testRefusesAPatternOnlyPcreRuns(string $pattern, string $uses): void {
		$this->assertStringContainsString($uses, (string)PortablePatternCheck::findIncompatibility($pattern));
	}

	public function testTheEvaluatorRefusesAPatternOnlyPcreRuns(): void {
		$this->expectException(FormatRuleError::class);
		$this->expectExceptionMessage('possessive quantifiers');

		(new FormatRuleEvaluator())->assertValidRule(['match' => [['var' => 'value'], '^a++$']]);
	}

	public function testEveryCorePatternIsPortable(): void {
		$definitions = json_decode((string)file_get_contents(self::CORE_FORMATS_FILE), true, flags: JSON_THROW_ON_ERROR);
		foreach ($definitions as $definition) {
			array_walk_recursive($definition['rule'], function (mixed $value) use ($definition): void {
				if (is_string($value) && str_starts_with($value, '^')) {
					$this->assertNull(PortablePatternCheck::findIncompatibility($value), $definition['technicalName']);
				}
			});
		}
	}

	public function testACatastrophicPatternFailsInsteadOfHanging(): void {
		$started = microtime(true);
		try {
			$valid = (new FormatRuleEvaluator())->evaluate(['match' => [['var' => 'value'], '^(a+)+$']], str_repeat('a', 5000) . '!');
			$this->assertFalse($valid);
		} catch (FormatRuleError $e) {
			$this->assertStringContainsString('cannot be used', $e->getMessage());
		}
		$this->assertLessThan(5, microtime(true) - $started);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function portablePatterns(): iterable {
		foreach (self::vectors()['portable'] as $pattern) {
			yield $pattern => [$pattern];
		}
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function refusedPatterns(): iterable {
		foreach (self::vectors()['refused'] as $vector) {
			yield $vector['pattern'] => [$vector['pattern'], $vector['uses']];
		}
	}

	/**
	 * @return array{portable: list<string>, refused: list<array{pattern: string, uses: string}>}
	 */
	private static function vectors(): array {
		return json_decode((string)file_get_contents(self::VECTORS_FILE), true, flags: JSON_THROW_ON_ERROR);
	}
}
