<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Db\Column;
use OCA\Tables\Db\ColumnMapper;
use OCA\Tables\Db\Format;
use OCA\Tables\Db\Row2Mapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Formats\FormatRuleEvaluator;
use OCA\Tables\Service\FormatService;
use OCA\Tables\Service\FormatValueValidator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FormatValueValidatorTest extends TestCase {
	private FormatValueValidator $validator;
	private Column $column;

	protected function setUp(): void {
		$format = new Format();
		$format->setTechnicalName('nl.postcode');
		$format->setLabel('Postal code (NL)');
		$format->setType(Column::TYPE_TEXT);
		$format->setRule('{"match":[{"var":"value"},"^[1-9][0-9]{3} ?[A-Z]{2}$"]}');
		$formatService = $this->createMock(FormatService::class);
		$formatService->method('findUsableFormat')->willReturn($format);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$this->validator = new FormatValueValidator(
			$formatService,
			new FormatRuleEvaluator(),
			$this->createMock(Row2Mapper::class),
			$this->createMock(ColumnMapper::class),
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
		$this->column = new Column();
		$this->column->setTitle('Postcode');
		$this->column->setType(Column::TYPE_TEXT);
		$this->column->setSubtype('nl.postcode');
	}

	public function testChecksValuesLongerThanTheBrowserChecks(): void {
		$this->expectException(BadRequestError::class);
		$this->expectExceptionMessage('Validation failed: Postcode did not match the criteria for Postal code (NL)');

		$this->validator->validate($this->column, '1234 AB' . str_repeat(' ', 300));
	}

	public function testLetsAValidValueThrough(): void {
		$this->validator->validate($this->column, '1234 AB');
		$this->addToAssertionCount(1);
	}
}
