<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use OCA\Tables\Db\ColumnMapper;
use OCA\Tables\Db\Format;
use OCA\Tables\Db\FormatMapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\FormatImportConflictError;
use OCA\Tables\Errors\FormatInUseError;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Formats\FormatLabelTranslator;
use OCA\Tables\Formats\FormatPack;
use OCA\Tables\Formats\FormatPackReader;
use OCA\Tables\Formats\PackFormat;
use OCA\Tables\ResponseDefinitions;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;

/**
 * Installs, previews and removes format packs, and installs the format definitions a JSON Schema
 * carries in the same way.
 *
 * @psalm-import-type TablesFormatPack from ResponseDefinitions
 * @psalm-import-type TablesFormatPackItem from ResponseDefinitions
 * @psalm-import-type TablesFormatPackImport from ResponseDefinitions
 */
class FormatPackService {
	public const STATUS_NEW = 'new';
	public const STATUS_UNCHANGED = 'unchanged';
	public const STATUS_UPDATE = 'update';
	public const STATUS_CONFLICT = 'conflict';
	public const STATUS_CORE = 'core';

	public const RESOLUTION_KEEP = 'keep';
	public const RESOLUTION_REPLACE = 'replace';

	public function __construct(
		private readonly FormatPackReader $reader,
		private readonly FormatMapper $formatMapper,
		private readonly ColumnMapper $columnMapper,
		private readonly FormatValueValidator $formatValueValidator,
		private readonly FormatLabelTranslator $labelTranslator,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * The packs Tables ships, with how much of each is installed and whether it has newer rules.
	 *
	 * @return list<TablesFormatPack>
	 * @throws BadRequestError
	 * @throws Exception
	 */
	public function listShippedPacks(): array {
		$installedFormats = $this->installedFormatsByName();
		$packs = [];
		foreach ($this->reader->shippedPackNames() as $name) {
			$pack = $this->reader->readShipped($name);
			$installedCount = 0;
			$updateAvailable = false;
			foreach ($pack->formats as $packFormat) {
				$installed = $installedFormats[$packFormat->technicalName] ?? null;
				if ($installed?->getSource() !== $pack->source()) {
					continue;
				}
				$installedCount++;
				$updateAvailable = $updateAvailable || version_compare($packFormat->version, $installed->getVersion(), '>');
			}
			$packs[] = [
				'name' => $pack->name,
				'label' => $this->labelTranslator->translate($pack->label),
				'version' => $pack->version,
				'formatsCount' => count($pack->formats),
				'installedCount' => $installedCount,
				'updateAvailable' => $updateAvailable,
			];
		}
		return $packs;
	}

	/**
	 * What importing the pack would do to each of its formats.
	 *
	 * @return list<TablesFormatPackItem>
	 * @throws Exception
	 * @throws InternalError
	 */
	public function preview(FormatPack $pack): array {
		return $this->previewDefinitions($pack->formats, $pack->source());
	}

	/**
	 * What installing the definitions with the source would do to each of them. A definition is
	 * an update when the installed format has the same source and an older version.
	 *
	 * @param array<string, PackFormat> $definitions
	 * @return list<TablesFormatPackItem>
	 * @throws Exception
	 * @throws InternalError
	 */
	public function previewDefinitions(array $definitions, string $source): array {
		$installedFormats = $this->installedFormatsByName();
		$items = [];
		foreach ($definitions as $packFormat) {
			$installed = $installedFormats[$packFormat->technicalName] ?? null;
			$status = $this->statusOf($source, $packFormat, $installed);
			$impact = ['columnsCount' => 0, 'failingValuesCount' => 0];
			if ($installed !== null && in_array($status, [self::STATUS_UPDATE, self::STATUS_CONFLICT], true)) {
				$impact = $this->formatValueValidator->previewFormatChange($this->replacementOf($installed, $packFormat, $source));
			}
			$items[] = [
				'technicalName' => $packFormat->technicalName,
				'label' => $this->labelTranslator->translate($packFormat->label),
				'status' => $status,
				'version' => $packFormat->version,
				'installedVersion' => $installed?->getVersion(),
				'installedSource' => $installed?->getSource(),
				'columnsCount' => $impact['columnsCount'],
				'failingValuesCount' => $impact['failingValuesCount'],
			];
		}
		return $items;
	}

	/**
	 * Installs the pack. Every format that would change an installed one needs a resolution, keep
	 * or replace; formats with the name of a core format are never replaced.
	 *
	 * @param array<string, string> $resolutions keep or replace, by technical name
	 * @param string|null $userId who imports the pack, or null for occ
	 * @return TablesFormatPackImport
	 * @throws BadRequestError when a resolution is not keep or replace
	 * @throws FormatImportConflictError when formats that would change have no resolution
	 * @throws Exception
	 * @throws InternalError
	 */
	public function import(FormatPack $pack, array $resolutions, ?string $userId): array {
		return $this->importDefinitions($pack->formats, $pack->source(), $resolutions, $userId);
	}

	/**
	 * Installs the definitions with the source, as import() does for a pack.
	 *
	 * @param array<string, PackFormat> $definitions
	 * @param array<string, string> $resolutions keep or replace, by technical name
	 * @return TablesFormatPackImport
	 * @throws BadRequestError when a resolution is not keep or replace
	 * @throws FormatImportConflictError when formats that would change have no resolution
	 * @throws Exception
	 * @throws InternalError
	 */
	public function importDefinitions(array $definitions, string $source, array $resolutions, ?string $userId): array {
		foreach ($resolutions as $technicalName => $resolution) {
			if (!in_array($resolution, [self::RESOLUTION_KEEP, self::RESOLUTION_REPLACE], true)) {
				throw new BadRequestError('The resolution for ' . $technicalName . ' has to be keep or replace.');
			}
		}
		$preview = $this->previewDefinitions($definitions, $source);
		$unresolved = array_values(array_filter(
			$preview,
			static fn (array $item): bool => in_array($item['status'], [self::STATUS_UPDATE, self::STATUS_CONFLICT], true) && !isset($resolutions[$item['technicalName']]),
		));
		if ($unresolved !== []) {
			throw new FormatImportConflictError($unresolved);
		}

		$installedFormats = $this->installedFormatsByName();
		$result = ['created' => [], 'replaced' => [], 'kept' => [], 'unchanged' => [], 'skipped' => []];
		$now = $this->now();
		foreach ($preview as $item) {
			$technicalName = $item['technicalName'];
			$packFormat = $definitions[$technicalName];
			$installed = $installedFormats[$technicalName] ?? null;
			if ($installed === null) {
				$this->formatMapper->insert($this->createFormat($packFormat, $source, $userId, $now));
				$result['created'][] = $technicalName;
			} elseif ($item['status'] === self::STATUS_CORE) {
				$result['skipped'][] = $technicalName;
			} elseif ($item['status'] === self::STATUS_UNCHANGED) {
				$result['unchanged'][] = $technicalName;
			} elseif ($resolutions[$technicalName] === self::RESOLUTION_KEEP) {
				$result['kept'][] = $technicalName;
			} else {
				$replacement = $this->replacementOf($installed, $packFormat, $source);
				$replacement->setUpdatedAt($now);
				$replacement->setUpdatedBy($userId);
				$this->formatMapper->update($replacement);
				$result['replaced'][] = $technicalName;
			}
		}
		return $result;
	}

	/**
	 * Removes exactly the formats installed from the pack. Columns that use them keep their values
	 * and are reported as broken, so their number has to be confirmed first.
	 *
	 * @return list<string> the technical names of the removed formats
	 * @throws FormatInUseError when columns use the formats and their number was not confirmed
	 * @throws Exception
	 */
	public function remove(string $name, ?int $confirmedBrokenColumnsCount): array {
		$source = Format::SOURCE_PACK_PREFIX . $name;
		$formats = array_values(array_filter($this->formatMapper->findAll(), static fn (Format $format): bool => $format->getSource() === $source));
		$brokenColumnsCount = 0;
		foreach ($formats as $format) {
			$brokenColumnsCount += count($this->columnMapper->findAllUsingFormat($format->getType(), $format->getTechnicalName()));
		}
		if ($brokenColumnsCount > 0 && $confirmedBrokenColumnsCount !== $brokenColumnsCount) {
			throw new FormatInUseError($brokenColumnsCount);
		}
		foreach ($formats as $format) {
			$this->formatMapper->delete($format);
		}
		return array_map(static fn (Format $format): string => $format->getTechnicalName(), $formats);
	}

	/**
	 * @return 'new'|'unchanged'|'update'|'conflict'|'core'
	 */
	private function statusOf(string $source, PackFormat $packFormat, ?Format $installed): string {
		if ($installed === null) {
			return self::STATUS_NEW;
		}
		if ($installed->isShippedWithTables()) {
			return self::STATUS_CORE;
		}
		if ($installed->getType() === $packFormat->type && $installed->getRuleAsArray() == $packFormat->rule) {
			return self::STATUS_UNCHANGED;
		}
		if ($installed->getSource() === $source && version_compare($packFormat->version, $installed->getVersion(), '>')) {
			return self::STATUS_UPDATE;
		}
		return self::STATUS_CONFLICT;
	}

	private function createFormat(PackFormat $packFormat, string $source, ?string $userId, string $now): Format {
		$format = new Format();
		$format->setTechnicalName($packFormat->technicalName);
		$format->setType($packFormat->type);
		$format->setEnabled(true);
		$format->setCreatedAt($now);
		$format->setCreatedBy($userId);
		$this->applyPackDefinition($format, $packFormat, $source);
		$format->setUpdatedAt($now);
		$format->setUpdatedBy($userId);
		return $format;
	}

	/**
	 * The installed format with the incoming definition, not yet stored. Its type stays, as columns
	 * refer to the format by name and type.
	 */
	private function replacementOf(Format $installed, PackFormat $packFormat, string $source): Format {
		$replacement = clone $installed;
		$this->applyPackDefinition($replacement, $packFormat, $source);
		return $replacement;
	}

	private function applyPackDefinition(Format $format, PackFormat $packFormat, string $source): void {
		$format->setLabel($packFormat->label);
		$format->setExample($packFormat->example);
		$format->setRule(json_encode($packFormat->rule, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$format->setVersion($packFormat->version);
		$format->setSource($source);
	}

	/**
	 * @return array<string, Format>
	 * @throws Exception
	 */
	private function installedFormatsByName(): array {
		$formats = [];
		foreach ($this->formatMapper->findAll() as $format) {
			$formats[$format->getTechnicalName()] = $format;
		}
		return $formats;
	}

	private function now(): string {
		return $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
	}
}
