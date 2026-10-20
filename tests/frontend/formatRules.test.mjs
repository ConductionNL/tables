/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Runs the vectors that tests/unit/Formats/FormatRuleEvaluatorTest.php runs as well.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import { applyFormatRule, evaluateFormatRule, FormatRuleError } from '../../src/shared/utils/formatRules.js'

const readJson = (path) => JSON.parse(readFileSync(new URL(path, import.meta.url), 'utf8'))
const definitions = readJson('../../lib/Formats/core-formats.json')
const vectors = readJson('../formats/format-vectors.json')
const coreRules = Object.fromEntries(definitions.map(definition => [definition.technicalName, definition.rule]))

describe('core formats', () => {
	it('every core format has vectors', () => {
		assert.deepEqual(Object.keys(vectors.formats).sort(), Object.keys(coreRules).sort())
	})

	it('every core format accepts its example', () => {
		for (const definition of definitions) {
			assert.equal(evaluateFormatRule(definition.rule, definition.example), true, definition.technicalName + ' rejects its own example')
		}
	})

	for (const [technicalName, samples] of Object.entries(vectors.formats)) {
		for (const [group, valid] of [['valid', true], ['invalid', false]]) {
			for (const sample of samples[group]) {
				it(technicalName + (valid ? ' accepts ' : ' rejects ') + JSON.stringify(sample), () => {
					assert.equal(evaluateFormatRule(coreRules[technicalName], sample), valid)
				})
			}
		}
	}
})

describe('operations', () => {
	for (const vector of vectors.operations) {
		it(vector.description, () => {
			if (vector.error) {
				assert.throws(() => applyFormatRule(vector.rule, vector.value), FormatRuleError)
				return
			}
			if ('result' in vector) {
				assert.deepEqual(applyFormatRule(vector.rule, vector.value), vector.result)
			}
			if ('valid' in vector) {
				assert.equal(evaluateFormatRule(vector.rule, vector.value), vector.valid)
			}
		})
	}
})
