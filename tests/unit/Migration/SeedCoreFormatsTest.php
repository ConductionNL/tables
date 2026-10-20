<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Migration;

use OC\AppFramework\Utility\TimeFactory;
use OCA\Tables\Db\Format;
use OCA\Tables\Db\FormatMapper;
use OCA\Tables\Migration\SeedCoreFormats;
use OCA\Tables\Tests\Unit\Database\DatabaseTestCase;
use OCP\Migration\IOutput;

class SeedCoreFormatsTest extends DatabaseTestCase {
	private const CORE_FORMATS = ['color', 'duration', 'email', 'iban', 'semver', 'uri', 'url', 'uuid'];

	private FormatMapper $mapper;
	private SeedCoreFormats $seeder;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new FormatMapper($this->connectionAdapter);
		$this->seeder = new SeedCoreFormats($this->mapper, new TimeFactory());
		$this->deleteAllFormats();
	}

	protected function tearDown(): void {
		$this->deleteAllFormats();
		$this->runSeeder();
		parent::tearDown();
	}

	public function testInstallsEveryCoreFormatEnabledAndUnowned(): void {
		$this->runSeeder();

		$formats = $this->formatsByName();
		$this->assertSame(self::CORE_FORMATS, array_keys($formats));
		foreach ($formats as $format) {
			$this->assertTrue($format->isEnabled());
			$this->assertNull($format->getCreatedBy());
			$this->assertSame('text', $format->getType());
			$this->assertSame('1.0.0', $format->getVersion());
			$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $format->getCreatedAt());
			$this->assertNotEmpty($format->getRuleAsArray());
		}
	}

	public function testRunningTwiceDoesNotDuplicateFormats(): void {
		$this->runSeeder();
		$this->runSeeder();

		$this->assertCount(count(self::CORE_FORMATS), $this->mapper->findAll());
	}

	public function testRefreshesAnUnmodifiedCoreFormatFromAnOlderVersion(): void {
		$this->runSeeder();
		$email = $this->formatsByName()['email'];
		$email->setVersion('0.9.0');
		$email->setRule('{"==":[1,1]}');
		$email->setEnabled(false);
		$this->mapper->update($email);

		$this->runSeeder();

		$email = $this->formatsByName()['email'];
		$this->assertSame('1.0.0', $email->getVersion());
		$this->assertArrayHasKey('match', $email->getRuleAsArray());
		$this->assertFalse($email->isEnabled(), 'refreshing a definition keeps whether it is enabled');
	}

	public function testLeavesACoreFormatAnAdministratorChanged(): void {
		$this->runSeeder();
		$email = $this->formatsByName()['email'];
		$email->setVersion('0.9.0');
		$email->setRule('{"==":[1,1]}');
		$email->setUpdatedBy('admin');
		$this->mapper->update($email);

		$this->runSeeder();

		$email = $this->formatsByName()['email'];
		$this->assertSame('0.9.0', $email->getVersion());
		$this->assertSame(['==' => [1, 1]], $email->getRuleAsArray());
	}

	public function testLeavesFormatsAnAdministratorCreated(): void {
		$custom = new Format();
		$custom->setTechnicalName('postcode');
		$custom->setLabel('Postal code');
		$custom->setType('text');
		$custom->setRule('{"match":[{"var":"value"},"^[0-9]{4}$"]}');
		$custom->setEnabled(true);
		$custom->setVersion('1');
		$custom->setCreatedAt('2026-10-01 12:00:00');
		$custom->setCreatedBy('admin');
		$custom->setUpdatedAt('2026-10-01 12:00:00');
		$this->mapper->insert($custom);

		$this->runSeeder();

		$formats = $this->formatsByName();
		$this->assertCount(count(self::CORE_FORMATS) + 1, $formats);
		$this->assertSame('1', $formats['postcode']->getVersion());
	}

	private function runSeeder(): void {
		$this->seeder->run($this->createMock(IOutput::class));
	}

	/**
	 * @return array<string, Format>
	 */
	private function formatsByName(): array {
		$formats = [];
		foreach ($this->mapper->findAll() as $format) {
			$formats[$format->getTechnicalName()] = $format;
		}
		return $formats;
	}

	private function deleteAllFormats(): void {
		$this->connection->getQueryBuilder()->delete('tables_formats')->executeStatement();
	}
}
