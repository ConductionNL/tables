/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Every pattern that tests/unit/Formats/PortablePatternCheckTest.php lets through has to compile in the browser.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'

const vectors = JSON.parse(readFileSync(new URL('../formats/pattern-vectors.json', import.meta.url), 'utf8'))

describe('portable patterns', () => {
	for (const pattern of vectors.portable) {
		it(`compiles ${pattern}`, () => {
			assert.doesNotThrow(() => new RegExp(pattern, 'u'))
		})
	}
})
