<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Formats;

use OCA\Tables\Db\Column;
use OCA\Tables\Db\FormatMapper;
use OCA\Tables\Errors\BadRequestError;
use OCA\Tables\Errors\FormatRuleError;
use OCP\DB\Exception;
use OCP\IL10N;

/**
 * Reads pack files: the ones Tables ships, or one given to occ. A pack is only read when every
 * format in it is valid, so a pack is installed completely or not at all.
 *
 * A file has a pack name, a version, an English label and formats by technical name. Each format
 * has an English label, a type, a version, a rule and optionally an example and a note. A rule may
 * be {"sameAs": "<technical name>"}, which is replaced by a copy of that format's rule from the
 * same pack or from the installed formats, so what is stored never depends on another format.
 */
class FormatPackReader {
	private const PACKS_DIRECTORY = __DIR__ . '/packs';
	private const PACK_NAME_PATTERN = '/^[a-z][a-z0-9_-]*$/';

	public function __construct(
		private readonly FormatRuleEvaluator $evaluator,
		private readonly FormatMapper $formatMapper,
		private readonly IL10N $l,
	) {
	}

	/**
	 * @return list<string>
	 */
	public function shippedPackNames(): array {
		$names = array_map(static fn (string $file): string => basename($file, '.json'), glob(self::PACKS_DIRECTORY . '/*.json') ?: []);
		sort($names);
		return $names;
	}

	/**
	 * @throws BadRequestError
	 * @throws Exception
	 */
	public function readShipped(string $name): FormatPack {
		if (!in_array($name, $this->shippedPackNames(), true)) {
			throw $this->badRequest($this->l->t('Tables ships no format pack named %1$s.', [$name]));
		}
		return $this->readFile(self::PACKS_DIRECTORY . '/' . $name . '.json');
	}

	/**
	 * @throws BadRequestError
	 * @throws Exception
	 */
	public function readFile(string $path): FormatPack {
		$contents = is_readable($path) ? file_get_contents($path) : false;
		$data = $contents === false ? null : json_decode($contents, true);
		if (!is_array($data)) {
			throw $this->badRequest($this->l->t('The format pack %1$s cannot be read as JSON.', [$path]));
		}
		return $this->parse($data);
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @throws BadRequestError
	 * @throws Exception
	 */
	public function parse(array $data): FormatPack {
		$name = $data['pack'] ?? null;
		if (!is_string($name) || preg_match(self::PACK_NAME_PATTERN, $name) !== 1) {
			throw $this->badRequest($this->l->t('A format pack needs a name of lowercase letters, digits, underscores and hyphens.'));
		}
		$label = $data['label'] ?? null;
		$version = $data['version'] ?? null;
		$definitions = $data['formats'] ?? null;
		if (!is_string($label) || $label === '' || !is_string($version) || $version === '' || !is_array($definitions) || $definitions === []) {
			throw $this->badRequest($this->l->t('The format pack %1$s needs a label, a version and formats.', [$name]));
		}

		$formats = [];
		foreach ($definitions as $technicalName => $definition) {
			$technicalName = (string)$technicalName;
			if (!str_starts_with($technicalName, $name . '.')) {
				throw $this->badRequest($this->l->t('The format %1$s has to be named %2$s.… to be part of the pack %2$s.', [$technicalName, $name]));
			}
			if (!is_array($definition)) {
				throw $this->badRequest($this->l->t('The format %1$s in the pack %2$s is not an object.', [$technicalName, $name]));
			}
			$rule = $this->resolveRule($technicalName, $definitions, []);
			$formats[$technicalName] = $this->readFormat($technicalName, $definition, $rule);
		}
		return new FormatPack($name, $label, $version, $formats);
	}

	/**
	 * Checks one format definition, as a pack or a JSON Schema carries it, naming the format when
	 * it cannot be used.
	 *
	 * @param array<array-key, mixed> $definition
	 * @param array<string, mixed> $rule the rule of the definition, with any sameAs replaced
	 * @throws BadRequestError
	 */
	public function readFormat(string $technicalName, array $definition, array $rule): PackFormat {
		$label = $definition['label'] ?? null;
		$type = $definition['type'] ?? null;
		$version = $definition['version'] ?? null;
		$example = $definition['example'] ?? null;
		if (!is_string($label) || $label === '' || !is_string($version) || $version === '' || ($example !== null && !is_string($example))) {
			throw $this->badRequest($this->l->t('The format %1$s needs a label and a version, and its example has to be text.', [$technicalName]));
		}
		if (!is_string($type) || !Column::supportsFormats($type)) {
			throw $this->badRequest($this->l->t('The format %1$s has to be for columns of type text or number.', [$technicalName]));
		}
		try {
			$this->evaluator->assertValidRule($rule);
			if ($example !== null && !$this->evaluator->evaluate($rule, $type === Column::TYPE_NUMBER && is_numeric($example) ? (float)$example : $example)) {
				throw $this->badRequest($this->l->t('The example of the format %1$s does not satisfy its rule.', [$technicalName]));
			}
		} catch (FormatRuleError $e) {
			throw $this->badRequest($this->l->t('The rule of the format %1$s cannot be used: %2$s', [$technicalName, $e->getMessage()]));
		}
		return new PackFormat($technicalName, $label, $example, $type, $version, $rule);
	}

	/**
	 * @param array<array-key, mixed> $definitions
	 * @param list<string> $seen the formats whose sameAs led here, to stop at a cycle
	 * @return array<string, mixed>
	 * @throws BadRequestError
	 * @throws Exception
	 */
	private function resolveRule(string $technicalName, array $definitions, array $seen): array {
		if (in_array($technicalName, $seen, true)) {
			throw $this->badRequest($this->l->t('The formats %1$s refer to each other with sameAs.', [implode(', ', $seen)]));
		}
		$definition = $definitions[$technicalName] ?? null;
		if (!is_array($definition)) {
			return $this->findInstalledRule($technicalName, $seen);
		}
		$rule = $definition['rule'] ?? null;
		if (!is_array($rule)) {
			throw $this->badRequest($this->l->t('The format %1$s needs a rule.', [$technicalName]));
		}
		$sameAs = $rule['sameAs'] ?? null;
		if (count($rule) === 1 && is_string($sameAs)) {
			return $this->resolveRule($sameAs, $definitions, [...$seen, $technicalName]);
		}
		/** @var array<string, mixed> $rule */
		return $rule;
	}

	/**
	 * @param list<string> $seen
	 * @return array<string, mixed>
	 * @throws BadRequestError
	 * @throws Exception
	 */
	private function findInstalledRule(string $technicalName, array $seen): array {
		foreach ($this->formatMapper->findAll() as $format) {
			if ($format->getTechnicalName() === $technicalName) {
				return $format->getRuleAsArray();
			}
		}
		throw $this->badRequest($this->l->t('The format %1$s is the same as %2$s, which is neither in the pack nor installed.', [(string)end($seen), $technicalName]));
	}

	private function badRequest(string $translatedMessage): BadRequestError {
		return new BadRequestError($translatedMessage, 0, null, $translatedMessage);
	}
}
