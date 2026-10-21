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
use OCA\Tables\Db\Row2Mapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\FormatRuleError;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Formats\FormatLabelTranslator;
use OCA\Tables\Formats\FormatRuleEvaluator;
use OCP\DB\Exception;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Checks values of columns whose subtype names a format against that format, and against the
 * column's allowed pattern for text. Columns with a built-in subtype, and broken columns, are not
 * checked here, so they behave as before.
 */
class FormatValueValidator {
	public function __construct(
		private readonly FormatService $formatService,
		private readonly FormatRuleEvaluator $evaluator,
		private readonly Row2Mapper $row2Mapper,
		private readonly ColumnMapper $columnMapper,
		private readonly FormatLabelTranslator $labelTranslator,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @throws BadRequestError when the value does not satisfy the column's format
	 * @throws Exception
	 */
	public function validate(Column $column, mixed $value): void {
		$failure = $this->findFailure($column, $value);
		if ($failure !== null) {
			throw $failure;
		}
	}

	/**
	 * How many stored values of the column do not satisfy its format, or null when the column
	 * is not checked against a format.
	 *
	 * @throws Exception
	 * @throws InternalError
	 */
	public function countFailingValues(Column $column): ?int {
		$format = $this->findFormat($column);
		return $format === null ? null : $this->countFailingValuesAgainst($column, $format);
	}

	/**
	 * What a changed definition, not yet stored, would mean for the columns that use the format.
	 *
	 * @return array{columnsCount: int, failingValuesCount: int}
	 * @throws Exception
	 * @throws InternalError
	 */
	public function previewFormatChange(Format $changedFormat): array {
		$columns = $this->columnMapper->findAllUsingFormat($changedFormat->getType(), $changedFormat->getTechnicalName());
		$failingValuesCount = 0;
		foreach ($columns as $column) {
			$failingValuesCount += $this->countFailingValuesAgainst($column, $changedFormat);
		}
		return ['columnsCount' => count($columns), 'failingValuesCount' => $failingValuesCount];
	}

	/**
	 * @throws BadRequestError when the pattern cannot be used to check values
	 */
	public function assertPatternIsUsable(string $pattern): void {
		try {
			$this->evaluator->assertPortablePattern($pattern);
		} catch (FormatRuleError $e) {
			$translatedMessage = $this->l->t('The allowed pattern %1$s cannot be used: %2$s', [$pattern, $e->getMessage()]);
			throw new BadRequestError($translatedMessage, 0, $e, $translatedMessage);
		}
	}

	/**
	 * @throws Exception
	 * @throws InternalError
	 */
	private function countFailingValuesAgainst(Column $column, Format $format): int {
		$count = 0;
		foreach ($this->row2Mapper->findStoredValues($column) as $value) {
			if ($this->findFailureAgainst($column, $format, $value) !== null) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * @throws Exception
	 */
	private function findFailure(Column $column, mixed $value): ?BadRequestError {
		if ($value === null || $value === '') {
			return null;
		}
		$format = $this->findFormat($column);
		return $format === null ? null : $this->findFailureAgainst($column, $format, $value);
	}

	private function findFailureAgainst(Column $column, Format $format, mixed $value): ?BadRequestError {
		if ($value === null || $value === '') {
			return null;
		}

		$pattern = $column->getType() === Column::TYPE_TEXT ? (string)$column->getTextAllowedPattern() : '';
		if ($pattern !== '' && !$this->satisfies($column, $format, self::patternRule($pattern), $value)) {
			$translatedMessage = $this->l->t('Validation failed: %1$s did not match the pattern %2$s', [$column->getTitle(), $pattern]);
			return new BadRequestError($translatedMessage, 0, null, $translatedMessage);
		}
		if (!$this->satisfies($column, $format, $format->getRuleAsArray(), $value)) {
			return $this->formatMismatch($column, $format);
		}
		return null;
	}

	/**
	 * @throws Exception
	 */
	private function findFormat(Column $column): ?Format {
		$formatName = $column->getFormatName();
		return $formatName === null ? null : $this->formatService->findUsableFormat($formatName, $column->getType());
	}

	/**
	 * A rule that cannot be evaluated does not let values through: the administrator has to fix
	 * the format, or the column's pattern, first.
	 *
	 * @param array<string, mixed> $rule
	 */
	private function satisfies(Column $column, Format $format, array $rule, mixed $value): bool {
		try {
			return $this->evaluator->evaluate($rule, $value);
		} catch (FormatRuleError $e) {
			$this->logger->error('Format ' . $format->getTechnicalName() . ' cannot check values of column ' . $column->getId(), ['exception' => $e]);
			return false;
		}
	}

	private function formatMismatch(Column $column, Format $format): BadRequestError {
		$example = $format->getExample();
		$label = $this->labelTranslator->translateFormat($format);
		$translatedMessage = $example === null || $example === ''
			? $this->l->t('Validation failed: %1$s did not match the criteria for %2$s', [$column->getTitle(), $label])
			: $this->l->t('Validation failed: %1$s did not match the criteria for %2$s, such as %3$s', [$column->getTitle(), $label, $example]);
		return new BadRequestError($translatedMessage, 0, null, $translatedMessage);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function patternRule(string $pattern): array {
		return ['match' => [['var' => 'value'], $pattern]];
	}
}
