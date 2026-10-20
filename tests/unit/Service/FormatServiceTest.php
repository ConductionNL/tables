<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use DateTime;
use OCA\Tables\Db\Format;
use OCA\Tables\Db\FormatMapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Formats\FormatRuleEvaluator;
use OCA\Tables\Service\FormatService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FormatServiceTest extends TestCase {
	private const NOW = '2026-10-20 09:00:00';
	private const POSTCODE_RULE = ['match' => [['var' => 'value'], '^[0-9]{4} ?[A-Z]{2}$']];

	private FormatMapper&MockObject $mapper;
	/** @var list<Format> */
	private array $inserted = [];
	private FormatService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(FormatMapper::class);
		$this->mapper->method('findAll')->willReturnCallback(fn (): array => [
			self::format(1, 'email', 'text', true, null),
			self::format(2, 'iban', 'text', false, null),
			self::format(3, 'percentage', 'number', true, 'admin'),
			...$this->inserted,
		]);
		$this->mapper->method('find')->willReturnCallback(fn (int $id): Format => match ($id) {
			1 => self::format(1, 'email', 'text', true, null),
			2 => self::format(2, 'iban', 'text', false, null),
			3 => self::format(3, 'percentage', 'number', true, 'admin'),
		});
		$this->mapper->method('insert')->willReturnCallback(function (Format $format): Format {
			$this->inserted[] = $format;
			return $format;
		});
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mapper->method('delete')->willReturnArgument(0);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime(self::NOW));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$this->service = new FormatService($this->mapper, new FormatRuleEvaluator(), $timeFactory, $l10n);
	}

	public function testFindsAnEnabledFormatForItsType(): void {
		$this->assertSame('email', $this->service->findUsableFormat('email', 'text')?->getTechnicalName());
	}

	public function testDoesNotUseAFormatForAnotherType(): void {
		$this->assertNull($this->service->findUsableFormat('email', 'number'));
	}

	public function testDoesNotUseADisabledFormat(): void {
		$this->assertNull($this->service->findUsableFormat('iban', 'text'));
	}

	public function testDoesNotUseAnUnknownFormat(): void {
		$this->assertNull($this->service->findUsableFormat('nl_bsn', 'text'));
	}

	public function testDefinitionsListOnlyEnabledFormatsAndLeaveOutWhoChangedThem(): void {
		$this->assertSame(['email', 'percentage'], array_column($this->service->getEnabledDefinitions(), 'technicalName'));
		$this->assertSame(['technicalName', 'label', 'example', 'type', 'rule', 'version'], array_keys($this->service->getEnabledDefinitions()[0]));
	}

	public function testListsDisabledFormatsOnlyWhenAsked(): void {
		$this->assertCount(2, $this->service->findAll(false));
		$this->assertCount(3, $this->service->findAll(true));
	}

	public function testHidesADisabledFormatWhenAskedForEnabledOnes(): void {
		$this->expectException(NotFoundError::class);

		$this->service->find(2, false);
	}

	public function testCreatesAFormat(): void {
		$format = $this->service->create('nl_postcode', 'Dutch postcode', 'text', self::POSTCODE_RULE, '1234 AB', true, '1.0.0', 'admin');

		$this->assertSame('nl_postcode', $format->getTechnicalName());
		$this->assertSame(self::POSTCODE_RULE, $format->getRuleAsArray());
		$this->assertSame('admin', $format->getCreatedBy());
		$this->assertSame(self::NOW, $format->getCreatedAt());
	}

	public function testCreatedFormatsAreUsableRightAway(): void {
		$this->assertNull($this->service->findUsableFormat('nl_postcode', 'text'));

		$this->service->create('nl_postcode', 'Dutch postcode', 'text', self::POSTCODE_RULE, null, true, '1.0.0', 'admin');

		$this->assertNotNull($this->service->findUsableFormat('nl_postcode', 'text'));
	}

	/**
	 * @dataProvider invalidDefinitions
	 * @param array<string, mixed> $rule
	 */
	public function testRefusesAnInvalidDefinition(string $technicalName, string $type, array $rule, ?string $example, string $message): void {
		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage($message);

		$this->service->create($technicalName, 'Label', $type, $rule, $example, true, '1.0.0', 'admin');
	}

	/**
	 * @return array<string, array{string, string, array<string, mixed>, ?string, string}>
	 */
	public static function invalidDefinitions(): array {
		return [
			'a name with capitals' => ['NlPostcode', 'text', self::POSTCODE_RULE, null, 'The technical name must start with a letter'],
			'the name of a built-in subtype' => ['check', 'text', self::POSTCODE_RULE, null, 'The technical name check is a built-in column subtype.'],
			'a name that is taken' => ['email', 'text', self::POSTCODE_RULE, null, 'A format named email already exists.'],
			'a type without formats' => ['nl_postcode', 'selection', self::POSTCODE_RULE, null, 'Formats can only be made for columns of type text or number.'],
			'a rule that is no operation' => ['nl_postcode', 'text', ['match' => [], 'and' => []], null, 'The rule cannot be used'],
			'a forbidden operation' => ['nl_postcode', 'text', ['log' => [['var' => 'value']]], null, 'The operation log cannot be used in a rule.'],
			'an invalid pattern' => ['nl_postcode', 'text', ['match' => [['var' => 'value'], '(']], null, 'The rule cannot be used'],
			'an example that fails the rule' => ['nl_postcode', 'text', self::POSTCODE_RULE, '12345', 'The example 12345 does not satisfy the rule.'],
		];
	}

	public function testUpdatesTheDefinitionButKeepsTheNameAndType(): void {
		$format = $this->service->update(3, 'Percent', null, '', false, '2.0.0', 'other_admin');

		$this->assertSame('percentage', $format->getTechnicalName());
		$this->assertSame('number', $format->getType());
		$this->assertSame('Percent', $format->getLabel());
		$this->assertNull($format->getExample());
		$this->assertFalse($format->isEnabled());
		$this->assertSame('2.0.0', $format->getVersion());
		$this->assertSame('other_admin', $format->getUpdatedBy());
	}

	public function testDeletesAFormatAnAdministratorCreated(): void {
		$this->mapper->expects($this->once())->method('delete');

		$this->assertSame('percentage', $this->service->delete(3)->getTechnicalName());
	}

	public function testRefusesToDeleteAFormatShippedWithTheApp(): void {
		$this->mapper->expects($this->never())->method('delete');
		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage('Formats shipped with Tables cannot be deleted, but they can be disabled.');

		$this->service->delete(1);
	}

	private static function format(int $id, string $technicalName, string $type, bool $enabled, ?string $createdBy): Format {
		$format = new Format();
		$format->setId($id);
		$format->setTechnicalName($technicalName);
		$format->setLabel('Label of ' . $technicalName);
		$format->setType($type);
		$format->setRule('{"match":[{"var":"value"},"@"]}');
		$format->setEnabled($enabled);
		$format->setVersion('1.0.0');
		$format->setCreatedBy($createdBy);
		return $format;
	}
}
