<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Migration;

use OCA\Tables\Db\Format;
use OCA\Tables\Db\FormatMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Override;

/**
 * Installs the formats shipped with the app. A shipped format is refreshed when the app ships a
 * new version of it, unless an administrator has changed it since.
 *
 * @psalm-type CoreFormatDefinition = array{
 *     technicalName: string,
 *     label: string,
 *     example: ?string,
 *     type: string,
 *     version: string,
 *     rule: array<string, mixed>,
 * }
 */
class SeedCoreFormats implements IRepairStep {
	private const DEFINITIONS_FILE = __DIR__ . '/../Formats/core-formats.json';

	public function __construct(
		private readonly FormatMapper $formatMapper,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	#[Override]
	public function getName(): string {
		return 'Installing the core column formats';
	}

	#[Override]
	public function run(IOutput $output): void {
		$installedFormats = [];
		foreach ($this->formatMapper->findAll() as $format) {
			$installedFormats[$format->getTechnicalName()] = $format;
		}

		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		foreach ($this->loadDefinitions() as $definition) {
			$installedFormat = $installedFormats[$definition['technicalName']] ?? null;
			if ($installedFormat === null) {
				$this->formatMapper->insert($this->createFormat($definition, $now));
				continue;
			}
			if ($this->isUnmodifiedOutdatedCoreFormat($installedFormat, $definition)) {
				$this->applyDefinition($installedFormat, $definition);
				$installedFormat->setUpdatedAt($now);
				$this->formatMapper->update($installedFormat);
			}
		}
	}

	/**
	 * @param CoreFormatDefinition $definition
	 */
	private function createFormat(array $definition, string $now): Format {
		$format = new Format();
		$format->setTechnicalName($definition['technicalName']);
		$this->applyDefinition($format, $definition);
		$format->setEnabled(true);
		$format->setCreatedAt($now);
		$format->setUpdatedAt($now);
		return $format;
	}

	/**
	 * @param CoreFormatDefinition $definition
	 */
	private function applyDefinition(Format $format, array $definition): void {
		$format->setLabel($definition['label']);
		$format->setExample($definition['example']);
		$format->setType($definition['type']);
		$format->setRule(json_encode($definition['rule'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$format->setVersion($definition['version']);
	}

	/**
	 * @param CoreFormatDefinition $definition
	 */
	private function isUnmodifiedOutdatedCoreFormat(Format $format, array $definition): bool {
		return $format->getCreatedBy() === null
			&& $format->getUpdatedBy() === null
			&& $format->getVersion() !== $definition['version'];
	}

	/**
	 * @return list<CoreFormatDefinition>
	 */
	private function loadDefinitions(): array {
		/** @var list<CoreFormatDefinition> $definitions */
		$definitions = json_decode((string)file_get_contents(self::DEFINITIONS_FILE), true, flags: JSON_THROW_ON_ERROR);
		return $definitions;
	}
}
