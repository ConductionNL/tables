<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\JsonSchema;

use OCA\Tables\Db\Column;
use OCA\Tables\Db\Format;
use OCA\Tables\Db\Table;
use OCA\Tables\Service\FormatService;
use OCA\Tables\Service\RowObjectInputConverter;
use OCP\DB\Exception;

/**
 * Describes a table as a JSON Schema 2020-12 object schema, with one property per column, named as
 * rows are in the object shape. Standard keywords carry what JSON Schema can express; the rest
 * travels in x-tables-* keywords, so an export imports back without loss.
 */
class TableSchemaExporter {
	public const SCHEMA_DIALECT = 'https://json-schema.org/draft/2020-12/schema';

	/**
	 * Subtypes whose format JSON Schema defines as well. A url column exports as format uri, while
	 * its x-tables-subtype keeps the URL validation.
	 */
	public const STANDARD_FORMATS = [
		'email' => 'email',
		'uri' => 'uri',
		'url' => 'uri',
		'uuid' => 'uuid',
		'duration' => 'duration',
	];

	private const TIME_PATTERN = '^[0-9]{2}:[0-9]{2}$';

	public function __construct(
		private readonly FormatService $formatService,
	) {
	}

	/**
	 * @param Column[] $columns
	 * @return array<string, mixed>
	 * @throws Exception
	 */
	public function export(Table $table, array $columns, string $schemaUrl): array {
		$properties = [];
		$required = [];
		foreach ($columns as $column) {
			$name = RowObjectInputConverter::nameOf($column);
			$properties[$name] = $this->property($column);
			if ($column->getMandatory()) {
				$required[] = $name;
			}
		}

		$schema = [
			'$schema' => self::SCHEMA_DIALECT,
			'$id' => $schemaUrl,
			'title' => $table->getTitle(),
		];
		if ((string)$table->getDescription() !== '') {
			$schema['description'] = $table->getDescription();
		}
		$schema['type'] = 'object';
		$schema['properties'] = (object)$properties;
		if ($required !== []) {
			$schema['required'] = $required;
		}
		$schema['additionalProperties'] = false;
		$schema['x-tables-table'] = array_filter([
			'uuid' => $table->getUuid(),
			'emoji' => $table->getEmoji(),
		], static fn (mixed $value): bool => $value !== null && $value !== '');
		return $schema;
	}

	/**
	 * @return array<string, mixed>
	 * @throws Exception
	 */
	private function property(Column $column): array {
		$property = ['title' => $column->getTitle()];
		if ((string)$column->getDescription() !== '') {
			$property['description'] = $column->getDescription();
		}
		$property += match ($column->getType()) {
			Column::TYPE_TEXT => $this->textKeywords($column),
			Column::TYPE_NUMBER => $this->numberKeywords($column),
			Column::TYPE_SELECTION => $this->selectionKeywords($column),
			Column::TYPE_DATETIME => $this->datetimeKeywords($column),
			Column::TYPE_USERGROUP => $this->usergroupKeywords($column),
			Column::TYPE_RELATION => $this->relationKeywords(),
			default => [],
		};
		$property['x-tables-column-id'] = $column->getId();
		if ($column->getUuid() !== null) {
			$property['x-tables-uuid'] = $column->getUuid();
		}
		$property['x-tables-type'] = $column->getType();
		$property['x-tables-subtype'] = (string)$column->getSubtype();
		$customSettings = $column->getCustomSettingsArray();
		if ($customSettings !== []) {
			$property['x-tables-custom-settings'] = $customSettings;
		}
		return $property;
	}

	/**
	 * @return array<string, mixed>
	 * @throws Exception
	 */
	private function textKeywords(Column $column): array {
		if ($column->getSubtype() === Column::SUBTYPE_TEXT_LINK) {
			return $this->linkKeywords($column);
		}
		$keywords = ['type' => 'string'];
		if ($column->getSubtype() === 'rich') {
			$keywords['contentMediaType'] = 'text/html';
		}
		if ($column->getTextMaxLength() !== null) {
			$keywords['maxLength'] = $column->getTextMaxLength();
		}
		if ((string)$column->getTextDefault() !== '') {
			$keywords['default'] = $column->getTextDefault();
		}
		$keywords += $this->formatKeywords($column);
		$pattern = (string)$column->getTextAllowedPattern();
		if ($pattern !== '') {
			// only columns with a format enforce the allowed pattern, so only they promise it as pattern
			if ($column->getFormatName() !== null && !$column->isBroken()) {
				$keywords['pattern'] = $pattern;
			}
			$keywords['x-tables-allowed-pattern'] = $pattern;
		}
		if ($column->getTextUnique()) {
			$keywords['x-tables-unique'] = true;
		}
		return $keywords;
	}

	/**
	 * Links are stored as {title, url, providerId}; the allowed pattern lists the providers.
	 *
	 * @return array<string, mixed>
	 */
	private function linkKeywords(Column $column): array {
		$keywords = [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'url' => ['type' => 'string', 'format' => 'uri'],
				'providerId' => ['type' => 'string'],
			],
			'required' => ['url'],
		];
		$providers = (string)$column->getTextAllowedPattern();
		if ($providers !== '') {
			$keywords['x-tables-link-providers'] = explode(',', $providers);
		}
		return $keywords;
	}

	/**
	 * The standard format of the subtype, and for custom and pack formats their definition, so the
	 * export can be imported where that format is not installed. A broken column exports its
	 * subtype as format without a definition.
	 *
	 * @return array<string, mixed>
	 * @throws Exception
	 */
	private function formatKeywords(Column $column): array {
		$formatName = $column->getFormatName();
		if ($formatName === null) {
			return [];
		}
		$format = $this->formatService->findUsableFormat($formatName, $column->getType());
		if ($format === null) {
			return ['format' => $formatName, 'x-tables-broken' => true];
		}
		$keywords = [];
		if (isset(self::STANDARD_FORMATS[$formatName])) {
			$keywords['format'] = self::STANDARD_FORMATS[$formatName];
		}
		if ($format->getExample() !== null) {
			$keywords['examples'] = [$this->exampleValue($format)];
		}
		if (!$format->isShippedWithTables()) {
			$keywords['x-tables-format-definition'] = [
				'technicalName' => $format->getTechnicalName(),
				'label' => $format->getLabel(),
				'example' => $format->getExample(),
				'type' => $format->getType(),
				'rule' => $format->getRuleAsArray(),
				'version' => $format->getVersion(),
			];
		}
		return $keywords;
	}

	private function exampleValue(Format $format): string|float|int {
		$example = (string)$format->getExample();
		if ($format->getType() !== Column::TYPE_NUMBER || !is_numeric($example)) {
			return $example;
		}
		return str_contains($example, '.') ? (float)$example : (int)$example;
	}

	/**
	 * @return array<string, mixed>
	 * @throws Exception
	 */
	private function numberKeywords(Column $column): array {
		$decimals = $column->getNumberDecimals() ?? 0;
		$keywords = ['type' => $decimals === 0 ? 'integer' : 'number'];
		$minimum = $column->getNumberMin();
		$maximum = $column->getNumberMax();
		if ($column->getSubtype() === 'stars') {
			[$minimum, $maximum] = [0, 5];
		} elseif ($column->getSubtype() === 'progress') {
			[$minimum, $maximum] = [0, 100];
		}
		if ($minimum !== null) {
			$keywords['minimum'] = $this->number($minimum, $decimals);
		}
		if ($maximum !== null) {
			$keywords['maximum'] = $this->number($maximum, $decimals);
		}
		if ($column->getNumberDefault() !== null) {
			$keywords['default'] = $this->number($column->getNumberDefault(), $decimals);
		}
		$keywords += $this->formatKeywords($column);
		$keywords['x-tables-decimals'] = $decimals;
		if ((string)$column->getNumberPrefix() !== '') {
			$keywords['x-tables-prefix'] = $column->getNumberPrefix();
		}
		if ((string)$column->getNumberSuffix() !== '') {
			$keywords['x-tables-suffix'] = $column->getNumberSuffix();
		}
		return $keywords;
	}

	private function number(float|int $value, int $decimals): float|int {
		return $decimals === 0 ? (int)round($value) : (float)$value;
	}

	/**
	 * Selections hold option ids, so enum lists the ids and x-tables-options their labels.
	 *
	 * @return array<string, mixed>
	 */
	private function selectionKeywords(Column $column): array {
		if ($column->getSubtype() === Column::SUBTYPE_SELECTION_CHECK) {
			$keywords = ['type' => 'boolean'];
			if ($column->getSelectionDefault() !== null && $column->getSelectionDefault() !== '') {
				$keywords['default'] = $column->getSelectionDefault() === 'true';
			}
			return $keywords;
		}

		$options = array_map(
			static fn (array $option): array => ['id' => (int)$option['id'], 'label' => (string)$option['label']],
			$column->getSelectionOptionsArray() ?? [],
		);
		$ids = array_column($options, 'id');
		$default = $column->getSelectionDefault();
		if ($column->getSubtype() === 'multi') {
			$keywords = ['type' => 'array', 'items' => ['type' => 'integer', 'enum' => $ids], 'uniqueItems' => true];
			$defaultIds = json_decode((string)$default, true);
			if (is_array($defaultIds) && $defaultIds !== []) {
				$keywords['default'] = array_map('intval', $defaultIds);
			}
		} else {
			$keywords = ['type' => 'integer', 'enum' => $ids];
			if (is_numeric($default)) {
				$keywords['default'] = (int)$default;
			}
		}
		$keywords['x-tables-options'] = $options;
		return $keywords;
	}

	/**
	 * Datetimes read as ISO 8601 with their offset, dates as YYYY-MM-DD and times as HH:MM.
	 *
	 * @return array<string, mixed>
	 */
	private function datetimeKeywords(Column $column): array {
		$keywords = match ($column->getSubtype()) {
			Column::SUBTYPE_DATETIME_DATE => ['type' => 'string', 'format' => 'date'],
			Column::SUBTYPE_DATETIME_TIME => ['type' => 'string', 'pattern' => self::TIME_PATTERN],
			default => ['type' => 'string', 'format' => 'date-time'],
		};
		if ((string)$column->getDatetimeDefault() !== '') {
			$keywords['x-tables-default'] = $column->getDatetimeDefault();
		}
		return $keywords;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function usergroupKeywords(Column $column): array {
		$keywords = [
			'type' => 'array',
			'items' => [
				'type' => 'object',
				'properties' => ['id' => ['type' => 'string'], 'type' => ['type' => 'integer']],
				'required' => ['id', 'type'],
			],
		];
		if (!$column->getUsergroupMultipleItems()) {
			$keywords['maxItems'] = 1;
		}
		$keywords['x-tables-usergroup'] = [
			'multipleItems' => (bool)$column->getUsergroupMultipleItems(),
			'selectUsers' => (bool)$column->getUsergroupSelectUsers(),
			'selectGroups' => (bool)$column->getUsergroupSelectGroups(),
			'selectTeams' => (bool)$column->getUsergroupSelectTeams(),
			'showUserStatus' => (bool)$column->getShowUserStatus(),
			'default' => $column->getUsergroupDefaultArray(),
		];
		return $keywords;
	}

	/**
	 * Relations read as the uuid of the target row; where they point travels in the custom settings.
	 *
	 * @return array<string, mixed>
	 */
	private function relationKeywords(): array {
		return ['type' => 'string', 'format' => 'uuid'];
	}
}
