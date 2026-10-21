<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use OCA\Tables\Db\Column;
use OCA\Tables\Db\ColumnMapper;
use OCA\Tables\Db\Format;
use OCA\Tables\Db\FormatMapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\FormatInUseError;
use OCA\Tables\Errors\FormatRuleError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Formats\FormatRuleEvaluator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\IL10N;

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
	// dots separate a namespace, such as the pack in nl.bsn
	private const TECHNICAL_NAME_PATTERN = '/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)*$/';
	private const TECHNICAL_NAME_MAX_LENGTH = 64;
	private const LABEL_MAX_LENGTH = 255;
	private const EXAMPLE_MAX_LENGTH = 255;
	private const VERSION_MAX_LENGTH = 32;

	/** @var array<string, Format>|null */
	private ?array $enabledFormats = null;

	public function __construct(
		private readonly FormatMapper $formatMapper,
		private readonly ColumnMapper $columnMapper,
		private readonly FormatRuleEvaluator $evaluator,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l,
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
	 * @return list<Format>
	 * @throws Exception
	 */
	public function findAll(bool $includeDisabled): array {
		$formats = $this->formatMapper->findAll();
		return $includeDisabled ? $formats : array_values(array_filter($formats, static fn (Format $format): bool => $format->isEnabled()));
	}

	/**
	 * @throws NotFoundError
	 * @throws Exception
	 */
	public function find(int $id, bool $includeDisabled): Format {
		try {
			$format = $this->formatMapper->find($id);
		} catch (DoesNotExistException|MultipleObjectsReturnedException $e) {
			throw new NotFoundError('Format ' . $id . ' does not exist.', 0, $e);
		}
		if (!$includeDisabled && !$format->isEnabled()) {
			throw new NotFoundError('Format ' . $id . ' does not exist.');
		}
		return $format;
	}

	/**
	 * @param array<array-key, mixed> $rule
	 * @throws BadRequestError
	 * @throws Exception
	 */
	public function create(string $technicalName, string $label, string $type, array $rule, ?string $example, bool $enabled, string $version, string $userId): Format {
		$this->assertValidTechnicalName($technicalName);
		if (!Column::supportsFormats($type)) {
			throw $this->badRequest($this->l->t('Formats can only be made for columns of type text or number.'));
		}

		$format = new Format();
		$format->setTechnicalName($technicalName);
		$format->setType($type);
		$this->applyDefinition($format, $label, $rule, $example, $version);
		$format->setEnabled($enabled);
		$format->setSource(Format::SOURCE_CUSTOM);
		$now = $this->now();
		$format->setCreatedAt($now);
		$format->setCreatedBy($userId);
		$format->setUpdatedAt($now);
		$format->setUpdatedBy($userId);

		$this->enabledFormats = null;
		return $this->formatMapper->insert($format);
	}

	/**
	 * The technical name and type stay as they are, so columns keep referring to the same format.
	 * Formats shipped with Tables can only be enabled or disabled.
	 *
	 * @param array<array-key, mixed>|null $rule
	 * @throws BadRequestError
	 * @throws NotFoundError
	 * @throws Exception
	 */
	public function update(int $id, ?string $label, ?array $rule, ?string $example, ?bool $enabled, ?string $version, string $userId): Format {
		$format = $this->find($id, true);
		$changesDefinition = $label !== null || $rule !== null || $example !== null || $version !== null;
		if ($format->isShippedWithTables() && $changesDefinition) {
			throw $this->badRequest($this->l->t('Formats shipped with Tables cannot be changed, but they can be enabled or disabled.'));
		}
		$this->applyChanges($format, $label, $rule, $example, $version);
		if ($enabled !== null) {
			$format->setEnabled($enabled);
		}
		$format->setUpdatedAt($this->now());
		$format->setUpdatedBy($userId);

		$this->enabledFormats = null;
		return $this->formatMapper->update($format);
	}

	/**
	 * The format as it would be after the changes, without storing it, to see what they affect.
	 *
	 * @param array<array-key, mixed>|null $rule
	 * @throws BadRequestError
	 * @throws NotFoundError
	 * @throws Exception
	 */
	public function previewUpdate(int $id, ?string $label, ?array $rule, ?string $example, ?string $version): Format {
		$format = clone $this->find($id, true);
		$this->applyChanges($format, $label, $rule, $example, $version);
		return $format;
	}

	/**
	 * Columns that use a deleted format keep their values and are reported as broken, so removing
	 * a format that is in use needs the number of those columns confirmed. Formats shipped with
	 * the app can only be disabled, as an upgrade would install them again.
	 *
	 * @param int|null $confirmedBrokenColumnsCount how many columns the caller was told will break
	 * @throws BadRequestError
	 * @throws FormatInUseError when columns use the format and their number was not confirmed
	 * @throws NotFoundError
	 * @throws Exception
	 */
	public function delete(int $id, ?int $confirmedBrokenColumnsCount): Format {
		$format = $this->find($id, true);
		if ($format->isShippedWithTables()) {
			throw $this->badRequest($this->l->t('Formats shipped with Tables cannot be deleted, but they can be disabled.'));
		}
		$brokenColumnsCount = count($this->columnMapper->findAllUsingFormat($format->getType(), $format->getTechnicalName()));
		if ($brokenColumnsCount > 0 && $confirmedBrokenColumnsCount !== $brokenColumnsCount) {
			throw new FormatInUseError($brokenColumnsCount);
		}

		$this->enabledFormats = null;
		return $this->formatMapper->delete($format);
	}

	/**
	 * @param array<array-key, mixed>|null $rule
	 * @throws BadRequestError
	 */
	private function applyChanges(Format $format, ?string $label, ?array $rule, ?string $example, ?string $version): void {
		$this->applyDefinition(
			$format,
			$label ?? $format->getLabel(),
			$rule ?? $format->getRuleAsArray(),
			$example ?? $format->getExample(),
			$version ?? $format->getVersion(),
		);
	}

	/**
	 * @param array<array-key, mixed> $rule
	 * @throws BadRequestError
	 */
	private function applyDefinition(Format $format, string $label, array $rule, ?string $example, string $version): void {
		$label = trim($label);
		if ($label === '' || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
			throw $this->badRequest($this->l->t('The label must be between 1 and %1$s characters long.', [self::LABEL_MAX_LENGTH]));
		}
		$version = trim($version);
		if ($version === '' || mb_strlen($version) > self::VERSION_MAX_LENGTH) {
			throw $this->badRequest($this->l->t('The version must be between 1 and %1$s characters long.', [self::VERSION_MAX_LENGTH]));
		}
		$example = $example === '' ? null : $example;
		if ($example !== null && mb_strlen($example) > self::EXAMPLE_MAX_LENGTH) {
			throw $this->badRequest($this->l->t('The example must be at most %1$s characters long.', [self::EXAMPLE_MAX_LENGTH]));
		}
		$this->assertValidRule($rule, $example, $format->getType());

		$format->setLabel($label);
		$format->setRule(json_encode($rule, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$format->setExample($example);
		$format->setVersion($version);
	}

	/**
	 * The example is shown in validation errors, so it has to satisfy the rule.
	 *
	 * @param array<array-key, mixed> $rule
	 * @throws BadRequestError
	 */
	private function assertValidRule(array $rule, ?string $example, string $type): void {
		try {
			$this->evaluator->assertValidRule($rule);
			if ($example !== null) {
				$exampleValue = $type === Column::TYPE_NUMBER && is_numeric($example) ? (float)$example : $example;
				if (!$this->evaluator->evaluate($rule, $exampleValue)) {
					throw $this->badRequest($this->l->t('The example %1$s does not satisfy the rule.', [$example]));
				}
			}
		} catch (FormatRuleError $e) {
			throw $this->badRequest($this->l->t('The rule cannot be used: %1$s', [$e->getMessage()]));
		}
	}

	/**
	 * @throws BadRequestError
	 * @throws Exception
	 */
	private function assertValidTechnicalName(string $technicalName): void {
		if (strlen($technicalName) > self::TECHNICAL_NAME_MAX_LENGTH || preg_match(self::TECHNICAL_NAME_PATTERN, $technicalName) !== 1) {
			throw $this->badRequest($this->l->t('The technical name must start with a letter, contain only lowercase letters, digits, underscores, hyphens and dots that separate a namespace, and be at most %1$s characters long.', [self::TECHNICAL_NAME_MAX_LENGTH]));
		}
		if (Column::isBuiltInSubtypeOfAnyType($technicalName)) {
			throw $this->badRequest($this->l->t('The technical name %1$s is a built-in column subtype.', [$technicalName]));
		}
		foreach ($this->formatMapper->findAll() as $format) {
			if ($format->getTechnicalName() === $technicalName) {
				throw $this->badRequest($this->l->t('A format named %1$s already exists.', [$technicalName]));
			}
		}
	}

	private function badRequest(string $translatedMessage): BadRequestError {
		return new BadRequestError($translatedMessage, 0, null, $translatedMessage);
	}

	private function now(): string {
		return $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
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
