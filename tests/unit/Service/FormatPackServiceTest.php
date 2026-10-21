<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use DateTime;
use OCA\Tables\Db\Column;
use OCA\Tables\Db\ColumnMapper;
use OCA\Tables\Db\Format;
use OCA\Tables\Db\FormatMapper;
use OCA\Tables\Errors\FormatImportConflictError;
use OCA\Tables\Errors\FormatInUseError;
use OCA\Tables\Formats\FormatLabelTranslator;
use OCA\Tables\Formats\FormatPack;
use OCA\Tables\Formats\FormatPackReader;
use OCA\Tables\Formats\PackFormat;
use OCA\Tables\Service\FormatPackService;
use OCA\Tables\Service\FormatValueValidator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FormatPackServiceTest extends TestCase {
	private const RULE = ['match' => [['var' => 'value'], '^[0-9]{4}$']];
	private const OTHER_RULE = ['match' => [['var' => 'value'], '^[0-9]{5}$']];

	private FormatMapper&MockObject $formatMapper;
	/** @var list<Format> */
	private array $installed = [];
	/** @var array<string, int> columns using a format, by technical name */
	private array $columnsUsing = [];
	private FormatPackService $service;

	protected function setUp(): void {
		$this->formatMapper = $this->createMock(FormatMapper::class);
		$this->formatMapper->method('findAll')->willReturnCallback(fn (): array => $this->installed);
		$this->formatMapper->method('insert')->willReturnArgument(0);
		$this->formatMapper->method('update')->willReturnArgument(0);
		$this->formatMapper->method('delete')->willReturnArgument(0);
		$columnMapper = $this->createMock(ColumnMapper::class);
		$columnMapper->method('findAllUsingFormat')->willReturnCallback(
			fn (string $type, string $technicalName): array => array_fill(0, $this->columnsUsing[$technicalName] ?? 0, new Column()),
		);
		$validator = $this->createMock(FormatValueValidator::class);
		$validator->method('previewFormatChange')->willReturn(['columnsCount' => 2, 'failingValuesCount' => 5]);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-10-21 10:00:00'));

		$this->service = new FormatPackService(
			$this->createMock(FormatPackReader::class),
			$this->formatMapper,
			$columnMapper,
			$validator,
			new FormatLabelTranslator($this->createMock(IL10N::class)),
			$timeFactory,
		);
	}

	public function testPreviewsEveryKindOfChange(): void {
		$this->installed = [
			self::installed('xx.same', 'pack:xx', '1.0.0', self::RULE),
			self::installed('xx.newer', 'pack:xx', '1.0.0', self::OTHER_RULE),
			self::installed('xx.other', 'custom', '3.0.0', self::OTHER_RULE),
			self::installed('xx.core', 'core', '1.0.0', self::OTHER_RULE),
		];

		$statuses = array_column($this->service->preview(self::pack()), 'status', 'technicalName');

		$this->assertSame([
			'xx.new' => 'new',
			'xx.same' => 'unchanged',
			'xx.newer' => 'update',
			'xx.other' => 'conflict',
			'xx.core' => 'core',
		], $statuses);
	}

	public function testShowsWhatReplacingAFormatInUseDoes(): void {
		$this->installed = [self::installed('xx.newer', 'pack:xx', '1.0.0', self::OTHER_RULE)];

		$items = array_column($this->service->preview(self::pack()), null, 'technicalName');

		$this->assertSame(2, $items['xx.newer']['columnsCount']);
		$this->assertSame(5, $items['xx.newer']['failingValuesCount']);
		$this->assertSame(0, $items['xx.new']['columnsCount']);
	}

	public function testCreatesTheFormatsOfThePackWithItsSource(): void {
		$inserted = [];
		$this->formatMapper->method('insert')->willReturnCallback(static function (Format $format) use (&$inserted): Format {
			$inserted[] = $format;
			return $format;
		});

		$result = $this->service->import(self::pack(), [], 'admin');

		$this->assertSame(['xx.new', 'xx.same', 'xx.newer', 'xx.other', 'xx.core'], $result['created']);
		$this->assertSame(['pack:xx'], array_values(array_unique(array_map(static fn (Format $format): string => $format->getSource(), $inserted))));
		$this->assertSame('2.0.0', $inserted[2]->getVersion());
	}

	public function testRefusesAnImportThatChangesFormatsWithoutAChoice(): void {
		$this->installed = [self::installed('xx.newer', 'pack:xx', '1.0.0', self::OTHER_RULE), self::installed('xx.other', 'custom', '3.0.0', self::OTHER_RULE)];
		$this->formatMapper->expects($this->never())->method('insert');

		try {
			$this->service->import(self::pack(), ['xx.newer' => 'keep'], 'admin');
			$this->fail('The import changed formats without a choice.');
		} catch (FormatImportConflictError $e) {
			$this->assertSame(['xx.other'], array_column($e->conflicts, 'technicalName'));
		}
	}

	public function testKeepsOrReplacesEachFormatAsChosen(): void {
		$this->installed = [
			self::installed('xx.newer', 'pack:xx', '1.0.0', self::OTHER_RULE),
			self::installed('xx.other', 'custom', '3.0.0', self::OTHER_RULE),
		];
		$updated = [];
		$this->formatMapper->method('update')->willReturnCallback(static function (Format $format) use (&$updated): Format {
			$updated[] = $format;
			return $format;
		});

		$result = $this->service->import(self::pack(), ['xx.newer' => 'replace', 'xx.other' => 'keep'], 'admin');

		$this->assertSame(['xx.newer'], $result['replaced']);
		$this->assertSame(['xx.other'], $result['kept']);
		$this->assertSame(self::RULE, $updated[0]->getRuleAsArray());
		$this->assertSame('2.0.0', $updated[0]->getVersion());
	}

	public function testNeverReplacesAFormatShippedWithTables(): void {
		$this->installed = [self::installed('xx.core', 'core', '1.0.0', self::OTHER_RULE)];
		$this->formatMapper->expects($this->never())->method('update');

		$this->assertSame(['xx.core'], $this->service->import(self::pack(), ['xx.core' => 'replace'], 'admin')['skipped']);
	}

	public function testRemovesExactlyTheFormatsOfThePack(): void {
		$this->installed = [
			self::installed('xx.one', 'pack:xx', '1.0.0', self::RULE),
			self::installed('xx.two', 'pack:xx', '1.0.0', self::RULE),
			self::installed('xx.mine', 'custom', '1.0.0', self::RULE),
			self::installed('yy.one', 'pack:yy', '1.0.0', self::RULE),
		];

		$this->assertSame(['xx.one', 'xx.two'], $this->service->remove('xx', null));
	}

	public function testRemovingAPackInUseNeedsTheBrokenColumnsConfirmed(): void {
		$this->installed = [self::installed('xx.one', 'pack:xx', '1.0.0', self::RULE), self::installed('xx.two', 'pack:xx', '1.0.0', self::RULE)];
		$this->columnsUsing = ['xx.one' => 2, 'xx.two' => 1];
		$this->formatMapper->expects($this->exactly(2))->method('delete');

		try {
			$this->service->remove('xx', null);
			$this->fail('The pack was removed although columns use it.');
		} catch (FormatInUseError $e) {
			$this->assertSame(3, $e->brokenColumnsCount);
		}
		$this->service->remove('xx', 3);
	}

	private static function pack(): FormatPack {
		$formats = [];
		foreach (['xx.new' => '1.0.0', 'xx.same' => '1.0.0', 'xx.newer' => '2.0.0', 'xx.other' => '1.0.0', 'xx.core' => '1.0.0'] as $technicalName => $version) {
			$formats[$technicalName] = new PackFormat($technicalName, 'Label', '1234', 'text', $version, self::RULE);
		}
		return new FormatPack('xx', 'Test', '1.0.0', $formats);
	}

	/**
	 * @param array<string, mixed> $rule
	 */
	private static function installed(string $technicalName, string $source, string $version, array $rule): Format {
		$format = new Format();
		$format->setTechnicalName($technicalName);
		$format->setLabel('Installed');
		$format->setType('text');
		$format->setRule(json_encode($rule));
		$format->setSource($source);
		$format->setVersion($version);
		$format->setEnabled(true);
		return $format;
	}
}
