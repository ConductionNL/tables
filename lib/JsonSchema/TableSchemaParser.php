<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\JsonSchema;

use OCA\Tables\Constants\ColumnType;
use OCA\Tables\Db\Column;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Formats\FormatPackReader;
use OCA\Tables\Formats\PackFormat;
use OCA\Tables\Vendor\Symfony\Component\Uid\Uuid;
use OCP\IL10N;

/**
 * Reads a JSON Schema object schema, 2020-12 or draft-07, as a table.
 *
 * x-tables-type and x-tables-subtype win over the standard keywords, so an export imports back
 * without loss. Schemas from other tools are mapped from their standard keywords: type, format,
 * enum, required, title, description, default, maxLength, pattern, minimum and maximum. A format
 * that names no built-in subtype becomes the subtype, and the column is broken until a format of
 * that name is installed.
 */
class TableSchemaParser {
	private const SUPPORTED_DIALECTS = [
		'https://json-schema.org/draft/2020-12/schema',
		'http://json-schema.org/draft-07/schema',
	];
	private const TECHNICAL_NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';
	private const DEFAULT_NUMBER_DECIMALS = 2;

	public function __construct(
		private readonly FormatPackReader $formatReader,
		private readonly IL10N $l,
	) {
	}

	/**
	 * @param array<array-key, mixed> $schema
	 * @throws BadRequestError naming the property that cannot be imported
	 */
	public function parse(array $schema): ParsedTableSchema {
		$this->assertSupportedDialect($schema['$schema'] ?? null);
		$properties = $schema['properties'] ?? null;
		if (($schema['type'] ?? 'object') !== 'object' || !is_array($properties) || $properties === []) {
			throw $this->badRequest($this->l->t('A table can only be imported from an object schema with properties.'));
		}
		$required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

		$columns = [];
		$formatDefinitions = [];
		$technicalNames = [];
		foreach ($properties as $name => $property) {
			$name = (string)$name;
			if (!is_array($property)) {
				throw $this->badRequest($this->l->t('The property %1$s is not a schema.', [$name]));
			}
			$column = $this->column($name, $property);
			$column['technicalName'] = $this->uniqueTechnicalName($name, $technicalNames);
			$technicalNames[] = $column['technicalName'];
			$column['mandatory'] = in_array($name, $required, true);
			$columns[] = $column;

			$definition = $this->formatDefinition($name, $property, $column);
			if ($definition !== null) {
				$formatDefinitions[$definition->technicalName] = $definition;
			}
		}

		$table = is_array($schema['x-tables-table'] ?? null) ? $schema['x-tables-table'] : [];
		return new ParsedTableSchema(
			is_string($schema['title'] ?? null) && $schema['title'] !== '' ? $schema['title'] : $this->l->t('Imported table'),
			is_string($schema['description'] ?? null) ? $schema['description'] : '',
			is_string($table['emoji'] ?? null) ? $table['emoji'] : null,
			$columns,
			$formatDefinitions,
		);
	}

	/**
	 * @throws BadRequestError
	 */
	private function assertSupportedDialect(mixed $dialect): void {
		if ($dialect === null) {
			return;
		}
		$dialect = is_string($dialect) ? rtrim($dialect, '#') : '';
		if (!in_array($dialect, self::SUPPORTED_DIALECTS, true)) {
			throw $this->badRequest($this->l->t('Only JSON Schema 2020-12 and draft-07 can be imported.'));
		}
	}

	/**
	 * @param array<array-key, mixed> $property
	 * @return array<string, mixed>
	 * @throws BadRequestError
	 */
	private function column(string $name, array $property): array {
		$column = self::emptyColumn();
		$column['title'] = is_string($property['title'] ?? null) && $property['title'] !== '' ? $property['title'] : $name;
		$column['description'] = is_string($property['description'] ?? null) ? $property['description'] : '';
		if (is_string($property['x-tables-uuid'] ?? null) && Uuid::isValid($property['x-tables-uuid'])) {
			$column['uuid'] = $property['x-tables-uuid'];
		}
		if (is_array($property['x-tables-custom-settings'] ?? null)) {
			$column['customSettings'] = $property['x-tables-custom-settings'];
		}

		[$type, $subtype] = isset($property['x-tables-type'])
			? $this->declaredType($name, $property)
			: $this->inferredType($name, $property);
		$column['type'] = $type;
		$column['subtype'] = $subtype;

		return match ($type) {
			Column::TYPE_TEXT => $this->textDetails($column, $property),
			Column::TYPE_NUMBER => $this->numberDetails($column, $property),
			Column::TYPE_SELECTION => $this->selectionDetails($column, $property),
			Column::TYPE_DATETIME => $this->datetimeDetails($column, $property),
			Column::TYPE_USERGROUP => $this->usergroupDetails($column, $property),
			default => $column,
		};
	}

	/**
	 * The type and subtype a Tables export states. Only text and number subtypes may name a format
	 * that is not installed; other subtypes decide how values are stored.
	 *
	 * @param array<array-key, mixed> $property
	 * @return array{string, string}
	 * @throws BadRequestError
	 */
	private function declaredType(string $name, array $property): array {
		$type = $property['x-tables-type'];
		$subtype = $property['x-tables-subtype'] ?? '';
		if (!is_string($type) || ColumnType::tryFrom($type) === null || !is_string($subtype)) {
			throw $this->badRequest($this->l->t('The property %1$s has an unknown x-tables-type.', [$name]));
		}
		if (!Column::isBuiltInSubtype($type, $subtype) && !Column::supportsFormats($type)) {
			throw $this->badRequest($this->l->t('The property %1$s has the subtype %2$s, which columns of type %3$s do not have.', [$name, $subtype, $type]));
		}
		return [$type, $subtype];
	}

	/**
	 * The type and subtype standard keywords describe.
	 *
	 * @param array<array-key, mixed> $property
	 * @return array{string, string}
	 * @throws BadRequestError
	 */
	private function inferredType(string $name, array $property): array {
		$type = $this->jsonType($property);
		$format = is_string($property['format'] ?? null) ? $property['format'] : null;
		if (isset($property['enum']) && in_array($type, ['string', 'integer', 'number'], true)) {
			return [Column::TYPE_SELECTION, ''];
		}
		return match ($type) {
			'string' => match ($format) {
				'date-time' => [Column::TYPE_DATETIME, ''],
				'date' => [Column::TYPE_DATETIME, Column::SUBTYPE_DATETIME_DATE],
				'time' => [Column::TYPE_DATETIME, Column::SUBTYPE_DATETIME_TIME],
				null => [Column::TYPE_TEXT, ($property['contentMediaType'] ?? null) === 'text/html' ? 'rich' : Column::SUBTYPE_TEXT_LINE],
				// a format is a subtype; one that is not installed leaves the column broken
				default => [Column::TYPE_TEXT, $format],
			},
			'integer', 'number' => [Column::TYPE_NUMBER, ''],
			'boolean' => [Column::TYPE_SELECTION, Column::SUBTYPE_SELECTION_CHECK],
			'array' => is_array($property['items']['enum'] ?? null)
				? [Column::TYPE_SELECTION, 'multi']
				: throw $this->badRequest($this->l->t('The property %1$s is a list, which cannot be imported yet unless it lists options.', [$name])),
			'object' => throw $this->badRequest($this->l->t('The property %1$s is a nested object, which cannot be imported yet.', [$name])),
			default => [Column::TYPE_TEXT, Column::SUBTYPE_TEXT_LINE],
		};
	}

	/**
	 * The JSON type of a property, ignoring null in a list such as ["string", "null"].
	 *
	 * @param array<array-key, mixed> $property
	 */
	private function jsonType(array $property): ?string {
		$type = $property['type'] ?? null;
		if (is_array($type)) {
			$type = array_values(array_filter($type, static fn (mixed $item): bool => $item !== 'null'))[0] ?? null;
		}
		return is_string($type) ? $type : null;
	}

	/**
	 * @param array<string, mixed> $column
	 * @param array<array-key, mixed> $property
	 * @return array<string, mixed>
	 */
	private function textDetails(array $column, array $property): array {
		if (is_int($property['maxLength'] ?? null)) {
			$column['textMaxLength'] = $property['maxLength'];
		}
		if (is_string($property['default'] ?? null)) {
			$column['textDefault'] = $property['default'];
		}
		$column['textUnique'] = ($property['x-tables-unique'] ?? false) === true;
		if ($column['subtype'] === Column::SUBTYPE_TEXT_LINK) {
			$providers = $property['x-tables-link-providers'] ?? null;
			$column['textAllowedPattern'] = is_array($providers) ? implode(',', array_filter($providers, 'is_string')) : null;
			return $column;
		}
		$pattern = $property['x-tables-allowed-pattern'] ?? $property['pattern'] ?? null;
		if (is_string($pattern)) {
			$column['textAllowedPattern'] = $pattern;
		}
		return $column;
	}

	/**
	 * @param array<string, mixed> $column
	 * @param array<array-key, mixed> $property
	 * @return array<string, mixed>
	 */
	private function numberDetails(array $column, array $property): array {
		$decimals = $property['x-tables-decimals'] ?? null;
		$column['numberDecimals'] = is_int($decimals) ? $decimals : ($this->jsonType($property) === 'integer' ? 0 : self::DEFAULT_NUMBER_DECIMALS);
		// stars and progress have their own fixed range
		if ($column['subtype'] === '' || Column::formatNameOf(Column::TYPE_NUMBER, $column['subtype']) !== null) {
			$column['numberMin'] = self::numberOrNull($property['minimum'] ?? null);
			$column['numberMax'] = self::numberOrNull($property['maximum'] ?? null);
		}
		$column['numberDefault'] = self::numberOrNull($property['default'] ?? null);
		$column['numberPrefix'] = is_string($property['x-tables-prefix'] ?? null) ? $property['x-tables-prefix'] : '';
		$column['numberSuffix'] = is_string($property['x-tables-suffix'] ?? null) ? $property['x-tables-suffix'] : '';
		return $column;
	}

	/**
	 * Option ids and labels come from x-tables-options; otherwise every enum value becomes an
	 * option with that value as its label.
	 *
	 * @param array<string, mixed> $column
	 * @param array<array-key, mixed> $property
	 * @return array<string, mixed>
	 */
	private function selectionDetails(array $column, array $property): array {
		$default = $property['default'] ?? null;
		if ($column['subtype'] === Column::SUBTYPE_SELECTION_CHECK) {
			$column['selectionOptions'] = null;
			$column['selectionDefault'] = is_bool($default) ? ($default ? 'true' : 'false') : null;
			return $column;
		}

		$options = $this->options($property);
		$column['selectionOptions'] = $options;
		$idOf = static function (mixed $value) use ($options, $property): ?int {
			if (isset($property['x-tables-options'])) {
				return is_int($value) ? $value : null;
			}
			foreach ($options as $option) {
				if ($option['label'] === (string)$value) {
					return $option['id'];
				}
			}
			return null;
		};
		if ($column['subtype'] === 'multi') {
			$ids = is_array($default) ? array_values(array_filter(array_map($idOf, $default), 'is_int')) : [];
			$column['selectionDefault'] = $ids === [] ? null : json_encode($ids);
		} else {
			$id = $default === null ? null : $idOf($default);
			$column['selectionDefault'] = $id === null ? null : (string)$id;
		}
		return $column;
	}

	/**
	 * @param array<array-key, mixed> $property
	 * @return list<array{id: int, label: string}>
	 */
	private function options(array $property): array {
		if (is_array($property['x-tables-options'] ?? null)) {
			$options = [];
			foreach ($property['x-tables-options'] as $option) {
				if (is_array($option) && is_int($option['id'] ?? null) && is_scalar($option['label'] ?? null)) {
					$options[] = ['id' => $option['id'], 'label' => (string)$option['label']];
				}
			}
			return $options;
		}
		$values = $property['enum'] ?? $property['items']['enum'] ?? [];
		$options = [];
		foreach (is_array($values) ? array_values($values) : [] as $index => $value) {
			if (is_scalar($value)) {
				$options[] = ['id' => $index + 1, 'label' => (string)$value];
			}
		}
		return $options;
	}

	/**
	 * @param array<string, mixed> $column
	 * @param array<array-key, mixed> $property
	 * @return array<string, mixed>
	 */
	private function datetimeDetails(array $column, array $property): array {
		$default = $property['x-tables-default'] ?? null;
		$column['datetimeDefault'] = is_string($default) ? $default : '';
		return $column;
	}

	/**
	 * @param array<string, mixed> $column
	 * @param array<array-key, mixed> $property
	 * @return array<string, mixed>
	 */
	private function usergroupDetails(array $column, array $property): array {
		$settings = is_array($property['x-tables-usergroup'] ?? null) ? $property['x-tables-usergroup'] : [];
		$column['usergroupMultipleItems'] = ($settings['multipleItems'] ?? false) === true;
		$column['usergroupSelectUsers'] = ($settings['selectUsers'] ?? true) === true;
		$column['usergroupSelectGroups'] = ($settings['selectGroups'] ?? false) === true;
		$column['usergroupSelectTeams'] = ($settings['selectTeams'] ?? false) === true;
		$column['showUserStatus'] = ($settings['showUserStatus'] ?? false) === true;
		$column['usergroupDefault'] = is_array($settings['default'] ?? null) ? array_values($settings['default']) : [];
		return $column;
	}

	/**
	 * The definition the schema carries for the format a column names, checked as a pack format is.
	 *
	 * @param array<array-key, mixed> $property
	 * @param array<string, mixed> $column
	 * @throws BadRequestError
	 */
	private function formatDefinition(string $name, array $property, array $column): ?PackFormat {
		$definition = $property['x-tables-format-definition'] ?? null;
		if (!is_array($definition)) {
			return null;
		}
		$formatName = Column::formatNameOf((string)$column['type'], (string)$column['subtype']);
		if ($formatName === null || ($definition['technicalName'] ?? null) !== $formatName) {
			throw $this->badRequest($this->l->t('The format definition of the property %1$s is not for its subtype.', [$name]));
		}
		$rule = $definition['rule'] ?? null;
		if (!is_array($rule)) {
			throw $this->badRequest($this->l->t('The format %1$s needs a rule.', [$formatName]));
		}
		/** @var array<string, mixed> $rule */
		return $this->formatReader->readFormat($formatName, $definition, $rule);
	}

	/**
	 * The property name as technical name when it is one, otherwise in snake case, so a schema
	 * from another tool keeps its names readable.
	 *
	 * @param list<string> $taken
	 */
	private function uniqueTechnicalName(string $name, array $taken): string {
		$technicalName = $name;
		if (preg_match(self::TECHNICAL_NAME_PATTERN, $technicalName) !== 1) {
			$technicalName = strtolower((string)preg_replace(['/([a-z0-9])([A-Z])/', '/[^A-Za-z0-9]+/'], ['$1_$2', '_'], $name));
			$technicalName = trim($technicalName, '_');
			if (preg_match('/^[a-z]/', $technicalName) !== 1) {
				$technicalName = 'column_' . $technicalName;
			}
		}
		$unique = $technicalName;
		for ($i = 2; in_array($unique, $taken, true); $i++) {
			$unique = $technicalName . '_' . $i;
		}
		return $unique;
	}

	private static function numberOrNull(mixed $value): ?float {
		return is_int($value) || is_float($value) ? (float)$value : null;
	}

	/**
	 * A column as ColumnService::importColumn() takes it, before the property fills it in.
	 *
	 * @return array<string, mixed>
	 */
	private static function emptyColumn(): array {
		return [
			'uuid' => null,
			'title' => '',
			'technicalName' => null,
			'type' => Column::TYPE_TEXT,
			'subtype' => Column::SUBTYPE_TEXT_LINE,
			'mandatory' => false,
			'description' => '',
			'numberDefault' => null,
			'numberMin' => null,
			'numberMax' => null,
			'numberDecimals' => null,
			'numberPrefix' => '',
			'numberSuffix' => '',
			'textDefault' => null,
			'textAllowedPattern' => null,
			'textMaxLength' => null,
			'textUnique' => false,
			'selectionOptions' => [],
			'selectionDefault' => null,
			'datetimeDefault' => '',
			'usergroupDefault' => [],
			'usergroupMultipleItems' => false,
			'usergroupSelectUsers' => false,
			'usergroupSelectGroups' => false,
			'usergroupSelectTeams' => false,
			'showUserStatus' => false,
			'customSettings' => [],
		];
	}

	private function badRequest(string $translatedMessage): BadRequestError {
		return new BadRequestError($translatedMessage, 0, null, $translatedMessage);
	}
}
