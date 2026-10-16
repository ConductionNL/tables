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
 * The widget types a grid page can hold. Every type has a schema for its settings;
 * the frontend renders its form from it and saved widgets are validated against it.
 * A type with a source shows a table or view of the application.
 *
 * @psalm-import-type TablesWidgetValue from ResponseDefinitions
 * @psalm-import-type TablesWidgetSettingSchema from ResponseDefinitions
 * @psalm-import-type TablesWidgetType from ResponseDefinitions
 */
class GridWidgetTypes {
	public const TYPE_HEADER = 'header';
	public const TYPE_TEXT = 'text';
	public const TYPE_DATA = 'data';

	public const GRID_COLUMNS = 12;

	private const HEX_COLOR = '/^#[0-9a-fA-F]{3,6}$/';

	public function __construct(
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @return array<string, TablesWidgetType>
	 */
	public function all(): array {
		return [
			self::TYPE_HEADER => [
				'type' => self::TYPE_HEADER,
				'title' => $this->l10n->t('Header'),
				'defaultWidth' => 12,
				'defaultHeight' => 2,
				'hasSource' => false,
				'settings' => [
					'heading' => ['type' => 'string', 'title' => $this->l10n->t('Heading'), 'default' => '', 'required' => true],
					'subheading' => ['type' => 'string', 'title' => $this->l10n->t('Subheading'), 'default' => ''],
					'textAlign' => ['type' => 'enum', 'title' => $this->l10n->t('Text alignment'), 'default' => 'left', 'options' => [
						['value' => 'left', 'label' => $this->l10n->t('Start')],
						['value' => 'center', 'label' => $this->l10n->t('Centered')],
						['value' => 'right', 'label' => $this->l10n->t('End')],
					]],
					'backgroundColor' => ['type' => 'color', 'title' => $this->l10n->t('Background color'), 'default' => ''],
					'textColor' => ['type' => 'color', 'title' => $this->l10n->t('Text color'), 'default' => ''],
				],
			],
			self::TYPE_TEXT => [
				'type' => self::TYPE_TEXT,
				'title' => $this->l10n->t('Description'),
				'defaultWidth' => 6,
				'defaultHeight' => 2,
				'hasSource' => false,
				'settings' => [
					'text' => ['type' => 'text', 'title' => $this->l10n->t('Text'), 'default' => ''],
				],
			],
			self::TYPE_DATA => [
				'type' => self::TYPE_DATA,
				'title' => $this->l10n->t('Table or view'),
				'defaultWidth' => 12,
				'defaultHeight' => 5,
				'hasSource' => true,
				'settings' => [],
			],
		];
	}

	/**
	 * @return TablesWidgetType
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
	 * Checks the settings of a widget against the schema of its type and returns them
	 * with defaults filled in and unknown settings dropped.
	 *
	 * @return array<string, TablesWidgetValue>
	 * @throws BadRequestError
	 */
	public function sanitizeSettings(string $type, mixed $settings): array {
		$schema = $this->get($type)['settings'];
		$settings ??= [];
		if (!is_array($settings)) {
			throw new BadRequestError('The settings of a ' . $type . ' widget must be an object.');
		}
		$sanitized = [];
		foreach ($schema as $name => $property) {
			$value = array_key_exists($name, $settings) ? $settings[$name] : $property['default'];
			$sanitized[$name] = $this->sanitizeValue($type . '.' . $name, $property, $value);
		}
		return $sanitized;
	}

	/**
	 * @param TablesWidgetSettingSchema $property
	 * @return TablesWidgetValue
	 * @throws BadRequestError
	 */
	private function sanitizeValue(string $label, array $property, mixed $value): string|bool|int|null {
		return match ($property['type']) {
			'string', 'text' => $this->sanitizeText($label, $value, $property['required'] ?? false),
			'color' => $this->sanitizeColor($label, $value),
			'boolean' => is_bool($value) ? $value : throw new BadRequestError('Widget setting ' . $label . ' must be true or false.'),
			'enum' => $this->sanitizeEnum($label, $value, array_column($property['options'] ?? [], 'value')),
		};
	}

	/**
	 * @throws BadRequestError
	 */
	private function sanitizeText(string $label, mixed $value, bool $isRequired): string {
		$value ??= '';
		if (!is_string($value)) {
			throw new BadRequestError('Widget setting ' . $label . ' must be a string.');
		}
		if ($isRequired && trim($value) === '') {
			throw new BadRequestError('Widget setting ' . $label . ' is required.');
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
			throw new BadRequestError('Widget setting ' . $label . ' must be a hex color.');
		}
		return $value;
	}

	/**
	 * @param list<string> $allowed
	 * @throws BadRequestError
	 */
	private function sanitizeEnum(string $label, mixed $value, array $allowed): string {
		if (!is_string($value) || !in_array($value, $allowed, true)) {
			throw new BadRequestError('Widget setting ' . $label . ' must be one of: ' . implode(', ', $allowed) . '.');
		}
		return $value;
	}
}
