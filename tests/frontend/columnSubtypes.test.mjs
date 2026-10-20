/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Runs the cases that tests/unit/Db/ColumnSubtypeTest.php runs as well.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import { getBaseSubtype, getFormatName } from '../../src/shared/utils/columnSubtypes.js'

const cases = JSON.parse(readFileSync(new URL('../formats/subtype-vectors.json', import.meta.url), 'utf8'))

describe('column subtypes', () => {
	for (const column of cases) {
		it(`${column.type} with subtype "${column.subtype}" behaves as "${column.baseSubtype}"`, () => {
			assert.equal(getBaseSubtype(column), column.baseSubtype)
			assert.equal(getFormatName(column), column.formatName)
		})
	}
})
