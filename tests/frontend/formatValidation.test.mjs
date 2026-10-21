/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { findFormatFailure } from '../../src/shared/utils/formatValidation.js'

const email = { technicalName: 'email', type: 'text', rule: { match: [{ var: 'value' }, '^[^@ ]+@[^@ ]+$'] } }
const even = { technicalName: 'even', type: 'number', rule: { '===': [{ '%': [{ var: 'value' }, 2] }, 0] } }
const textColumn = (extra = {}) => ({ type: 'text-line', format: email, broken: false, textAllowedPattern: '', ...extra })

describe('findFormatFailure', () => {
	it('lets a value through that satisfies the format', () => {
		assert.equal(findFormatFailure(textColumn(), 'zed@example.org'), null)
	})

	it('reports a value that fails the format', () => {
		assert.equal(findFormatFailure(textColumn(), 'not an address'), 'format')
	})

	it('does not check empty values', () => {
		assert.equal(findFormatFailure(textColumn(), ''), null)
		assert.equal(findFormatFailure(textColumn(), null), null)
	})

	it('checks the allowed pattern before the format', () => {
		assert.equal(findFormatFailure(textColumn({ textAllowedPattern: '@example\\.org$' }), 'zed@example.com'), 'pattern')
	})

	it('does not check a broken column', () => {
		assert.equal(findFormatFailure(textColumn({ broken: true }), 'not an address'), null)
	})

	it('does not check a column without a format definition', () => {
		assert.equal(findFormatFailure(textColumn({ format: null, textAllowedPattern: '^x$' }), 'y'), null)
	})

	it('does not use a format meant for another type', () => {
		assert.equal(findFormatFailure({ type: 'number', format: email, broken: false }, 5), null)
	})

	it('checks number input as a number', () => {
		assert.equal(findFormatFailure({ type: 'number', format: even, broken: false }, '4'), null)
		assert.equal(findFormatFailure({ type: 'number', format: even, broken: false }, '5'), 'format')
	})

	it('leaves values longer than the browser checks to the server', () => {
		assert.equal(findFormatFailure(textColumn(), 'x'.repeat(256)), null)
		assert.equal(findFormatFailure(textColumn(), 'x'.repeat(255)), 'format')
	})

	it('checks a uri up to 2048 characters', () => {
		const uri = { technicalName: 'uri', type: 'text', rule: { match: [{ var: 'value' }, '^[a-z]+:.+$'] } }
		assert.equal(findFormatFailure(textColumn({ format: uri }), ' '.repeat(2048)), 'format')
		assert.equal(findFormatFailure(textColumn({ format: uri }), ' '.repeat(2049)), null)
	})

	it('treats a rule that cannot be evaluated as failing', () => {
		assert.equal(findFormatFailure(textColumn({ textAllowedPattern: '(' }), 'zed@example.org'), 'pattern')
	})
})
