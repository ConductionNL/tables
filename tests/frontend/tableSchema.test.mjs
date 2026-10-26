/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The export that tests/unit/JsonSchema/TableSchemaExporterTest.php produces for its fixture table.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import Ajv2020 from 'ajv/dist/2020.js'

const schema = JSON.parse(readFileSync(new URL('../formats/table-schema-export.json', import.meta.url), 'utf8'))
// x-tables-* keywords are annotations, and formats such as nl.bsn are not JSON Schema's to check
const ajv = new Ajv2020({ strict: false, validateFormats: false })

const row = {
	name: 'Jane Doe',
	email: 'jane@example.org',
	website: { title: 'Example', url: 'https://example.org', providerId: 'url' },
	revenue: 1250.5,
	employees: 12,
	rating: 4,
	status: 2,
	sectors: [1, 2],
	active: true,
	last_contact: '2026-10-16T14:30:00+02:00',
	since: '2026-01-01',
	call_at: '09:30',
	owner: [{ id: 'alice', type: 0 }],
	contact: '0192a6e4-0000-7000-8000-000000000001',
}

describe('exported table schema', () => {
	it('is valid against the JSON Schema 2020-12 meta-schema', () => {
		assert.equal(ajv.validateSchema(schema), true, JSON.stringify(ajv.errors))
	})

	it('accepts a row in the object shape', () => {
		const validate = ajv.compile(schema)
		assert.equal(validate(row), true, JSON.stringify(validate.errors))
	})

	it('refuses an option id that is not in the column', () => {
		assert.equal(ajv.compile(schema)({ ...row, status: 9 }), false)
	})

	it('refuses a property that is no column', () => {
		assert.equal(ajv.compile(schema)({ ...row, nickname: 'J' }), false)
	})

	it('refuses a row without a mandatory value', () => {
		const { email, ...withoutEmail } = row
		assert.equal(ajv.compile(schema)(withoutEmail), false)
	})
})
