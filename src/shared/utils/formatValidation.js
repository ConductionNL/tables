/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { evaluateFormatRule, FormatRuleError } from './formatRules.js'

/**
 * Which check a value of a column with a format fails: its allowed pattern, its format, or none.
 * Mirrors FormatValueValidator on the server, which has the final say.
 *
 * @param {object} column a parsed column, carrying its format definition and allowed pattern
 * @param {unknown} value the value as the form holds it
 * @return {'pattern'|'format'|null}
 */
export function findFormatFailure(column, value) {
	const baseType = column.type?.split('-')[0]
	if (value === null || value === undefined || value === '' || column.broken || column.format?.type !== baseType) {
		return null
	}
	const checkedValue = baseType === 'number' && typeof value === 'string' ? Number(value) : value
	const pattern = baseType === 'text' ? (column.textAllowedPattern ?? '') : ''
	if (pattern !== '' && !satisfies({ match: [{ var: 'value' }, pattern] }, checkedValue)) {
		return 'pattern'
	}
	if (!satisfies(column.format.rule, checkedValue)) {
		return 'format'
	}
	return null
}

/**
 * A rule that cannot be evaluated does not let values through, as on the server.
 *
 * @param {object} rule a JSON Logic rule
 * @param {unknown} value the value to check
 * @return {boolean}
 */
function satisfies(rule, value) {
	try {
		return evaluateFormatRule(rule, value)
	} catch (error) {
		if (error instanceof FormatRuleError) {
			return false
		}
		throw error
	}
}
