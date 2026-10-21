/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Runs the pack vectors that tests/unit/Formats/FormatPackReaderTest.php runs as well.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import { evaluateFormatRule } from '../../src/shared/utils/formatRules.js'

const readJson = (path) => JSON.parse(readFileSync(new URL(path, import.meta.url), 'utf8'))
const packs = ['nl', 'de'].map(name => readJson(`../../lib/Formats/packs/${name}.json`))
const vectors = readJson('../formats/pack-vectors.json')

// a pack-local sameAs is a copy of the rule it names, as FormatPackReader stores it
const rules = {}
for (const pack of packs) {
	for (const [technicalName, format] of Object.entries(pack.formats)) {
		let rule = format.rule
		while (rule.sameAs) {
			rule = pack.formats[rule.sameAs].rule
		}
		rules[technicalName] = rule
	}
}

describe('format packs', () => {
	for (const pack of packs) {
		for (const [technicalName, format] of Object.entries(pack.formats)) {
			it(`${technicalName} accepts its example`, () => {
				assert.equal(evaluateFormatRule(rules[technicalName], format.example), true)
			})
		}
	}

	for (const [technicalName, cases] of Object.entries(vectors)) {
		for (const [value, valid] of Object.entries(cases)) {
			it(`${technicalName} ${valid ? 'accepts' : 'rejects'} ${value}`, () => {
				assert.equal(evaluateFormatRule(rules[technicalName], value), valid)
			})
		}
	}
})
