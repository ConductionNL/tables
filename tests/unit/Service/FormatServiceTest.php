<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OCA\Tables\Db\Format;
use OCA\Tables\Db\FormatMapper;
use OCA\Tables\Service\FormatService;
use PHPUnit\Framework\TestCase;

class FormatServiceTest extends TestCase {
	private FormatService $service;

	protected function setUp(): void {
		$mapper = $this->createMock(FormatMapper::class);
		$mapper->expects($this->once())->method('findAll')->willReturn([
			self::format('email', 'text', true),
			self::format('iban', 'text', false),
			self::format('percentage', 'number', true),
		]);
		$this->service = new FormatService($mapper);
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
		$this->service->findUsableFormat('email', 'text');

		$this->assertSame(['email', 'percentage'], array_column($this->service->getEnabledDefinitions(), 'technicalName'));
		$this->assertSame(['technicalName', 'label', 'example', 'type', 'rule', 'version'], array_keys($this->service->getEnabledDefinitions()[0]));
	}

	private static function format(string $technicalName, string $type, bool $enabled): Format {
		$format = new Format();
		$format->setTechnicalName($technicalName);
		$format->setLabel('Label of ' . $technicalName);
		$format->setType($type);
		$format->setRule('{"match":[{"var":"value"},"@"]}');
		$format->setEnabled($enabled);
		$format->setVersion('1.0.0');
		$format->setCreatedBy('admin');
		$format->setUpdatedBy('admin');
		return $format;
	}
}
