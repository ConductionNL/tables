<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Formats;

use OCA\Tables\Db\Format;
use OCA\Tables\Db\FormatMapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Formats\FormatPackReader;
use OCA\Tables\Formats\FormatRuleEvaluator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Runs the pack vectors that tests/frontend/formatPacks.test.mjs runs as well.
 */
class FormatPackReaderTest extends TestCase {
	private const VECTORS_FILE = __DIR__ . '/../../formats/pack-vectors.json';
	private const POSTCODE_RULE = ['match' => [['var' => 'value'], '^[0-9]{4}$']];

	private FormatPackReader $reader;

	protected function setUp(): void {
		$installed = new Format();
		$installed->setTechnicalName('email');
		$installed->setRule('{"match":[{"var":"value"},"@"]}');
		$mapper = $this->createMock(FormatMapper::class);
		$mapper->method('findAll')->willReturn([$installed]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$this->reader = new FormatPackReader(new FormatRuleEvaluator(), $mapper, $l10n);
	}

	public function testShipsTheDutchAndGermanPacks(): void {
		$this->assertSame(['de', 'nl'], $this->reader->shippedPackNames());
	}

	public function testEveryShippedFormatIsInItsPackNamespaceAndAcceptsItsExample(): void {
		$evaluator = new FormatRuleEvaluator();
		foreach ($this->reader->shippedPackNames() as $name) {
			$pack = $this->reader->readShipped($name);
			$this->assertCount(6, $pack->formats);
			foreach ($pack->formats as $technicalName => $format) {
				$this->assertStringStartsWith($name . '.', $technicalName);
				$this->assertTrue($evaluator->evaluate($format->rule, $format->example), $technicalName);
			}
		}
	}

	public function testSameAsIsStoredAsACopyOfTheRule(): void {
		$pack = $this->reader->readShipped('nl');

		$this->assertSame($pack->formats['nl.bsn']->rule, $pack->formats['nl.rsin']->rule);
		$this->assertArrayNotHasKey('sameAs', $pack->formats['nl.rsin']->rule);
	}

	public function testSameAsCanNameAnInstalledFormat(): void {
		$pack = $this->reader->parse(self::pack(['xx.mail' => ['rule' => ['sameAs' => 'email'], 'example' => 'jane@example.org']]));

		$this->assertSame(['match' => [['var' => 'value'], '@']], $pack->formats['xx.mail']->rule);
	}

	/**
	 * @dataProvider passingVectors
	 */
	public function testPackVector(string $technicalName, string $value, bool $valid): void {
		static $rules = null;
		if ($rules === null) {
			$rules = [];
			foreach ($this->reader->shippedPackNames() as $name) {
				foreach ($this->reader->readShipped($name)->formats as $format) {
					$rules[$format->technicalName] = $format->rule;
				}
			}
		}
		$this->assertSame($valid, (new FormatRuleEvaluator())->evaluate($rules[$technicalName], $value));
	}

	/**
	 * @dataProvider invalidPacks
	 * @param array<string, mixed> $pack
	 */
	public function testRefusesAnInvalidPackNamingTheFormat(array $pack, string $message): void {
		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage($message);

		$this->reader->parse($pack);
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function invalidPacks(): array {
		return [
			'a format outside the namespace' => [self::pack(['postcode' => []]), 'The format postcode has to be named xx.…'],
			'a regex JavaScript cannot run' => [self::pack(['xx.code' => ['rule' => ['match' => [['var' => 'value'], '^\\A[0-9]{4}\\z$']]]]), 'The rule of the format xx.code cannot be used'],
			'a possessive quantifier' => [self::pack(['xx.code' => ['rule' => ['match' => [['var' => 'value'], '^[0-9]++$']]]]), 'possessive quantifiers'],
			'an example that fails its rule' => [self::pack(['xx.code' => ['example' => '123']]), 'The example of the format xx.code does not satisfy its rule.'],
			'a sameAs that is nowhere' => [self::pack(['xx.code' => ['rule' => ['sameAs' => 'yy.code']]]), 'The format xx.code is the same as yy.code, which is neither in the pack nor installed.'],
			'formats that are the same as each other' => [self::pack(['xx.a' => ['rule' => ['sameAs' => 'xx.b']], 'xx.b' => ['rule' => ['sameAs' => 'xx.a']]]), 'refer to each other with sameAs'],
			'a type without formats' => [self::pack(['xx.code' => ['type' => 'selection']]), 'The format xx.code has to be for columns of type text or number.'],
		];
	}

	/**
	 * @return iterable<string, array{string, string, bool}>
	 */
	public static function passingVectors(): iterable {
		$vectors = json_decode((string)file_get_contents(self::VECTORS_FILE), true, flags: JSON_THROW_ON_ERROR);
		foreach ($vectors as $technicalName => $cases) {
			foreach ($cases as $value => $valid) {
				yield $technicalName . ' ' . ($valid ? 'accepts ' : 'rejects ') . $value => [$technicalName, (string)$value, $valid];
			}
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $formats overrides per format of a valid postcode format
	 * @return array<string, mixed>
	 */
	private static function pack(array $formats): array {
		$definitions = [];
		foreach ($formats as $technicalName => $overrides) {
			$definitions[$technicalName] = [...['label' => 'Code', 'example' => '1234', 'type' => 'text', 'version' => '1.0.0', 'rule' => self::POSTCODE_RULE], ...$overrides];
		}
		return ['pack' => 'xx', 'version' => '1.0.0', 'label' => 'Test', 'formats' => $definitions];
	}
}
