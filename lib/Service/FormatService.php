<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use OCA\Tables\Db\Format;
use OCA\Tables\Db\FormatMapper;
use OCP\DB\Exception;

/**
 * @psalm-type TablesFormatDefinition = array{
 *     technicalName: string,
 *     label: string,
 *     example: ?string,
 *     type: string,
 *     rule: array<string, mixed>,
 *     version: string,
 * }
 */
class FormatService {
	/** @var array<string, Format>|null */
	private ?array $enabledFormats = null;

	public function __construct(
		private readonly FormatMapper $formatMapper,
	) {
	}

	/**
	 * The enabled format with this technical name that applies to the column type, if there is one.
	 *
	 * @throws Exception
	 */
	public function findUsableFormat(string $technicalName, string $columnType): ?Format {
		$format = $this->getEnabledFormats()[$technicalName] ?? null;
		return $format?->getType() === $columnType ? $format : null;
	}

	/**
	 * What the browser needs to validate values, without who changed the formats and when.
	 *
	 * @return list<TablesFormatDefinition>
	 * @throws Exception
	 */
	public function getEnabledDefinitions(): array {
		return array_values(array_map(static fn (Format $format): array => [
			'technicalName' => $format->getTechnicalName(),
			'label' => $format->getLabel(),
			'example' => $format->getExample(),
			'type' => $format->getType(),
			'rule' => $format->getRuleAsArray(),
			'version' => $format->getVersion(),
		], $this->getEnabledFormats()));
	}

	/**
	 * @return array<string, Format>
	 * @throws Exception
	 */
	private function getEnabledFormats(): array {
		if ($this->enabledFormats === null) {
			$this->enabledFormats = [];
			foreach ($this->formatMapper->findAll() as $format) {
				if ($format->isEnabled()) {
					$this->enabledFormats[$format->getTechnicalName()] = $format;
				}
			}
		}
		return $this->enabledFormats;
	}
}
