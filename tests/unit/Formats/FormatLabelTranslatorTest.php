<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Formats;

use OCA\Tables\Db\Format;
use OCA\Tables\Formats\FormatLabelTranslator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class FormatLabelTranslatorTest extends TestCase {
	private const PACKS_DIRECTORY = __DIR__ . '/../../../lib/Formats/packs';
	private const CORE_FORMATS_FILE = __DIR__ . '/../../../lib/Formats/core-formats.json';

	private FormatLabelTranslator $translator;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'translated ' . $text);
		$this->translator = new FormatLabelTranslator($l10n);
	}

	public function testTranslatesTheLabelsTablesShips(): void {
		$this->assertSame('translated Citizen service number (BSN)', $this->translator->translateFormat(self::format('Citizen service number (BSN)', 'pack:nl')));
		$this->assertSame('translated Email address', $this->translator->translateFormat(self::format('Email address', 'core')));
	}

	public function testShowsTheLabelOfACustomFormatAsEntered(): void {
		$this->assertSame('Email address', $this->translator->translateFormat(self::format('Email address', 'custom')));
	}

	public function testEveryShippedLabelCanBeTranslated(): void {
		$labels = array_column(json_decode((string)file_get_contents(self::CORE_FORMATS_FILE), true), 'label');
		foreach (glob(self::PACKS_DIRECTORY . '/*.json') as $file) {
			$pack = json_decode((string)file_get_contents($file), true);
			$labels = [...$labels, $pack['label'], ...array_column($pack['formats'], 'label')];
		}
		foreach ($labels as $label) {
			$this->assertSame('translated ' . $label, $this->translator->translate($label), $label . ' is missing from FormatLabelTranslator');
		}
	}

	private static function format(string $label, string $source): Format {
		$format = new Format();
		$format->setLabel($label);
		$format->setSource($source);
		return $format;
	}
}
