<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Formats;

use OCA\Tables\Errors\FormatRuleError;
use OCA\Tables\Formats\FormatRuleEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Runs the vectors that tests/frontend/formatRules.test.mjs runs as well.
 */
class FormatRuleEvaluatorTest extends TestCase {
	private const CORE_FORMATS_FILE = __DIR__ . '/../../../lib/Formats/core-formats.json';
	private const VECTORS_FILE = __DIR__ . '/../../formats/format-vectors.json';

	private FormatRuleEvaluator $evaluator;

	protected function setUp(): void {
		$this->evaluator = new FormatRuleEvaluator();
	}

	public function testEveryCoreFormatHasVectors(): void {
		$this->assertEqualsCanonicalizing(
			array_keys(self::coreRules()),
			array_keys(self::vectors()['formats']),
		);
	}

	public function testEveryCoreFormatAcceptsItsExample(): void {
		$definitions = json_decode((string)file_get_contents(self::CORE_FORMATS_FILE), true, flags: JSON_THROW_ON_ERROR);
		foreach ($definitions as $definition) {
			$this->assertTrue(
				$this->evaluator->evaluate($definition['rule'], $definition['example']),
				$definition['technicalName'] . ' rejects its own example',
			);
		}
	}

	/**
	 * @dataProvider formatVectors
	 */
	public function testCoreFormat(string $technicalName, mixed $value, bool $valid): void {
		$this->assertSame($valid, $this->evaluator->evaluate(self::coreRules()[$technicalName], $value));
	}

	/**
	 * @param array<string, mixed> $vector
	 * @dataProvider operationVectors
	 */
	public function testOperation(array $vector): void {
		if ($vector['error'] ?? false) {
			$this->expectException(FormatRuleError::class);
			$this->evaluator->apply($vector['rule'], $vector['value']);
			return;
		}
		if (array_key_exists('result', $vector)) {
			$this->assertSame($vector['result'], $this->evaluator->apply($vector['rule'], $vector['value']));
		}
		if (array_key_exists('valid', $vector)) {
			$this->assertSame($vector['valid'], $this->evaluator->evaluate($vector['rule'], $vector['value']));
		}
	}

	public function testEveryCoreRuleIsAValidRule(): void {
		foreach (self::coreRules() as $rule) {
			$this->evaluator->assertValidRule($rule);
		}
		$this->addToAssertionCount(1);
	}

	/**
	 * @param array<array-key, mixed> $rule
	 * @dataProvider invalidRules
	 */
	public function testRefusesAnInvalidRule(array $rule): void {
		$this->expectException(FormatRuleError::class);

		$this->evaluator->assertValidRule($rule);
	}

	/**
	 * @return array<string, array{array<array-key, mixed>}>
	 */
	public static function invalidRules(): array {
		return [
			'a list instead of an operation' => [[['var' => 'value']]],
			'two operations' => [['match' => [['var' => 'value'], 'a'], 'chars' => [['var' => 'value']]]],
			'an unknown operation' => [['nope' => [1]]],
			'log, which has side effects' => [['!!' => [['log' => [['var' => 'value']]]]]],
			'method, which calls methods in the browser' => [['method' => [['var' => 'value'], 'toUpperCase']]],
			'a nested forbidden operation' => [['and' => [true, ['if' => [true, ['method' => ['x', 'y']], false]]]]],
			'an invalid pattern' => [['match' => [['var' => 'value'], '(']]],
			'an invalid flag' => [['match' => [['var' => 'value'], 'a', 'g']]],
		];
	}

	/**
	 * @return iterable<string, array{string, mixed, bool}>
	 */
	public static function formatVectors(): iterable {
		foreach (self::vectors()['formats'] as $technicalName => $values) {
			foreach (['valid' => true, 'invalid' => false] as $group => $valid) {
				foreach ($values[$group] as $sample) {
					$label = $technicalName . ($valid ? ' accepts ' : ' rejects ') . json_encode($sample, JSON_UNESCAPED_UNICODE);
					yield $label => [$technicalName, $sample, $valid];
				}
			}
		}
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function operationVectors(): iterable {
		foreach (self::vectors()['operations'] as $vector) {
			yield $vector['description'] => [$vector];
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function coreRules(): array {
		$definitions = json_decode((string)file_get_contents(self::CORE_FORMATS_FILE), true, flags: JSON_THROW_ON_ERROR);
		return array_column($definitions, 'rule', 'technicalName');
	}

	/**
	 * @return array{formats: array<string, array{valid: list<mixed>, invalid: list<mixed>}>, operations: list<array<string, mixed>>}
	 */
	private static function vectors(): array {
		return json_decode((string)file_get_contents(self::VECTORS_FILE), true, flags: JSON_THROW_ON_ERROR);
	}
}
