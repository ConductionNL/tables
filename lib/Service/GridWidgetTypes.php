<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Service;

use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\ResponseDefinitions;
use OCP\IL10N;

/**
 * The widget types a page can hold. Every type has a schema for its configuration
 * and one for its content; the frontend renders its forms from them and saved
 * widgets are validated against them.
 *
 * A data widget shows the node its page content row points at, so it has no
 * content of its own.
 *
 * @psalm-import-type TablesGridWidgetValue from ResponseDefinitions
 * @psalm-import-type TablesGridWidgetProperty from ResponseDefinitions
 * @psalm-import-type TablesGridWidgetType from ResponseDefinitions
 */
class GridWidgetTypes {
	public const TYPE_HEADER = 'header';
	public const TYPE_TEXT = 'text';
	public const TYPE_DATA = 'data';

	public const GRID_COLUMNS = 12;

	private const HEX_COLOR = '/^#[0-9a-fA-F]{3,6}$/';

	/**
	 * Default width, height, configuration and content of every type.
	 */
	private const DEFAULTS = [
		self::TYPE_HEADER => [
			'width' => 12,
			'height' => 2,
			'configuration' => ['title' => '', 'showTitle' => false],
			'content' => ['title' => '', 'subtitle' => '', 'textAlign' => 'left', 'backgroundColor' => '', 'textColor' => ''],
		],
		self::TYPE_TEXT => [
			'width' => 6,
			'height' => 2,
			'configuration' => ['title' => '', 'showTitle' => true],
			'content' => ['text' => ''],
		],
		self::TYPE_DATA => [
			'width' => 12,
			'height' => 5,
			'configuration' => ['title' => '', 'showTitle' => false],
			'content' => [],
		],
	];

	public function __construct(
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * The configuration and content a widget of the given type starts with.
	 * An unknown type has none.
	 *
	 * @return array{configuration: array<string, TablesGridWidgetValue>, content: array<string, TablesGridWidgetValue>}
	 */
	public static function defaults(string $type): array {
		return [
			'configuration' => self::DEFAULTS[$type]['configuration'] ?? [],
			'content' => self::DEFAULTS[$type]['content'] ?? [],
		];
	}

	/**
	 * @return array<string, TablesGridWidgetType>
	 */
	public function all(): array {
		return [
			self::TYPE_HEADER => $this->type(self::TYPE_HEADER, $this->l10n->t('Header'), [
				'title' => ['type' => 'string', 'title' => $this->l10n->t('Heading'), 'required' => true],
				'subtitle' => ['type' => 'string', 'title' => $this->l10n->t('Subheading')],
				'textAlign' => ['type' => 'enum', 'title' => $this->l10n->t('Text alignment'), 'options' => [
					['value' => 'left', 'label' => $this->l10n->t('Start')],
					['value' => 'center', 'label' => $this->l10n->t('Centered')],
					['value' => 'right', 'label' => $this->l10n->t('End')],
				]],
				'backgroundColor' => ['type' => 'color', 'title' => $this->l10n->t('Background color')],
				'textColor' => ['type' => 'color', 'title' => $this->l10n->t('Text color')],
			]),
			self::TYPE_TEXT => $this->type(self::TYPE_TEXT, $this->l10n->t('Description'), [
				'text' => ['type' => 'text', 'title' => $this->l10n->t('Text')],
			]),
			self::TYPE_DATA => $this->type(self::TYPE_DATA, $this->l10n->t('Table or view'), []),
		];
	}

	/**
	 * @return TablesGridWidgetType
	 * @throws BadRequestError
	 */
	public function get(string $type): array {
		$types = $this->all();
		if (!isset($types[$type])) {
			throw new BadRequestError('Unknown widget type "' . $type . '", expected one of: ' . implode(', ', array_keys($types)));
		}
		return $types[$type];
	}

	/**
	 * Checks the configuration and content of a widget against the schemas of its type
	 * and returns them with defaults filled in and unknown properties dropped.
	 *
	 * @return array{configuration: array<string, TablesGridWidgetValue>, content: array<string, TablesGridWidgetValue>}
	 * @throws BadRequestError
	 */
	public function sanitize(string $type, mixed $configuration, mixed $content): array {
		$schema = $this->get($type);
		$configuration ??= [];
		$content ??= [];
		if (!is_array($configuration)) {
			throw new BadRequestError('The configuration of a ' . $type . ' widget must be an object.');
		}
		if (!is_array($content)) {
			throw new BadRequestError('The content of a ' . $type . ' widget must be an object.');
		}
		return [
			'configuration' => $this->sanitizeProperties($type, $schema['configuration'], $configuration),
			'content' => $this->sanitizeProperties($type, $schema['properties'], $content),
		];
	}

	/**
	 * @param array<string, array{type: string, title: string, options?: list<array{value: string, label: string}>, required?: bool}> $properties
	 * @return TablesGridWidgetType
	 */
	private function type(string $type, string $title, array $properties): array {
		$defaults = self::DEFAULTS[$type];
		return [
			'type' => $type,
			'title' => $title,
			'defaultWidth' => $defaults['width'],
			'defaultHeight' => $defaults['height'],
			'configuration' => $this->withDefaults([
				'title' => ['type' => 'string', 'title' => $this->l10n->t('Title')],
				'showTitle' => ['type' => 'boolean', 'title' => $this->l10n->t('Show the title')],
			], $defaults['configuration']),
			'properties' => $this->withDefaults($properties, $defaults['content']),
		];
	}

	/**
	 * @param array<string, array{type: string, title: string, options?: list<array{value: string, label: string}>, required?: bool}> $properties
	 * @param array<string, TablesGridWidgetValue> $defaults
	 * @return array<string, TablesGridWidgetProperty>
	 */
	private function withDefaults(array $properties, array $defaults): array {
		$withDefaults = [];
		foreach ($properties as $name => $property) {
			$withDefaults[$name] = array_merge($property, ['default' => $defaults[$name] ?? null]);
		}
		return $withDefaults;
	}

	/**
	 * Every property checked against its schema, defaults filled in and unknown properties dropped.
	 *
	 * @param array<string, TablesGridWidgetProperty> $schema
	 * @param array<array-key, mixed> $values
	 * @return array<string, TablesGridWidgetValue>
	 * @throws BadRequestError
	 */
	private function sanitizeProperties(string $type, array $schema, array $values): array {
		$sanitized = [];
		foreach ($schema as $name => $property) {
			$value = array_key_exists($name, $values) ? $values[$name] : $property['default'];
			$sanitized[$name] = $this->sanitizeProperty($type . '.' . $name, $property, $value);
		}
		return $sanitized;
	}

	/**
	 * @param TablesGridWidgetProperty $property
	 * @return TablesGridWidgetValue
	 * @throws BadRequestError
	 */
	private function sanitizeProperty(string $label, array $property, mixed $value): string|bool|null {
		return match ($property['type']) {
			'string', 'text' => $this->sanitizeText($label, $value, $property['required'] ?? false),
			'color' => $this->sanitizeColor($label, $value),
			'boolean' => is_bool($value) ? $value : throw new BadRequestError('Widget property ' . $label . ' must be true or false.'),
			'enum' => $this->sanitizeEnum($label, $value, array_column($property['options'] ?? [], 'value')),
			default => throw new BadRequestError('Widget property ' . $label . ' has an unknown schema type.'),
		};
	}

	/**
	 * @throws BadRequestError
	 */
	private function sanitizeText(string $label, mixed $value, bool $isRequired): string {
		$value ??= '';
		if (!is_string($value)) {
			throw new BadRequestError('Widget property ' . $label . ' must be a string.');
		}
		if ($isRequired && trim($value) === '') {
			throw new BadRequestError('Widget property ' . $label . ' is required.');
		}
		return $value;
	}

	/**
	 * @throws BadRequestError
	 */
	private function sanitizeColor(string $label, mixed $value): string {
		if ($value === null || $value === '') {
			return '';
		}
		if (!is_string($value) || !preg_match(self::HEX_COLOR, $value)) {
			throw new BadRequestError('Widget property ' . $label . ' must be a hex color.');
		}
		return $value;
	}

	/**
	 * @param list<string> $allowed
	 * @throws BadRequestError
	 */
	private function sanitizeEnum(string $label, mixed $value, array $allowed): string {
		if (!is_string($value) || !in_array($value, $allowed, true)) {
			throw new BadRequestError('Widget property ' . $label . ' must be one of: ' . implode(', ', $allowed) . '.');
		}
		return $value;
	}
}
