<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Formats;

use OCA\Tables\Db\Format;
use OCP\IL10N;

/**
 * Translates the English labels of the formats and packs Tables ships. They are listed here so the
 * translation tools find them; a label an administrator entered is shown as entered.
 */
class FormatLabelTranslator {
	/** @var array<string, string>|null */
	private ?array $translations = null;

	public function __construct(
		private readonly IL10N $l,
	) {
	}

	public function translateFormat(Format $format): string {
		return $format->getSource() === Format::SOURCE_CUSTOM ? $format->getLabel() : $this->translate($format->getLabel());
	}

	public function translate(string $label): string {
		$this->translations ??= $this->loadTranslations();
		return $this->translations[$label] ?? $label;
	}

	/**
	 * @return array<string, string>
	 */
	private function loadTranslations(): array {
		return [
			// core formats
			'Email address' => $this->l->t('Email address'),
			'URI' => $this->l->t('URI'),
			'URL' => $this->l->t('URL'),
			'UUID' => $this->l->t('UUID'),
			'IBAN' => $this->l->t('IBAN'),
			'Color' => $this->l->t('Color'),
			'Semantic version' => $this->l->t('Semantic version'),
			'Duration' => $this->l->t('Duration'),
			// packs
			'Netherlands' => $this->l->t('Netherlands'),
			'Citizen service number (BSN)' => $this->l->t('Citizen service number (BSN)'),
			'Legal entities and partnerships information number (RSIN)' => $this->l->t('Legal entities and partnerships information number (RSIN)'),
			'Chamber of Commerce number (KvK)' => $this->l->t('Chamber of Commerce number (KvK)'),
			'Postal code (NL)' => $this->l->t('Postal code (NL)'),
			'Phone number (NL)' => $this->l->t('Phone number (NL)'),
			'VAT identification number (NL)' => $this->l->t('VAT identification number (NL)'),
			'Germany' => $this->l->t('Germany'),
			'Postal code (DE)' => $this->l->t('Postal code (DE)'),
			'Phone number (DE)' => $this->l->t('Phone number (DE)'),
			'Tax identification number (DE)' => $this->l->t('Tax identification number (DE)'),
			'VAT identification number (DE)' => $this->l->t('VAT identification number (DE)'),
			'Commercial register number (DE)' => $this->l->t('Commercial register number (DE)'),
			'Business identification number (DE)' => $this->l->t('Business identification number (DE)'),
		];
	}
}
