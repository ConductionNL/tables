/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import jsonLogic from 'json-logic-js'

/*
 * Evaluates format rules against a value, which rules read as {"var": "value"}.
 *
 * Rules are JSON Logic with three extra operations, match, chars and indexOf. substr is replaced
 * so that it gives the same results as lib/Formats/FormatRuleEvaluator.php: strings are sequences
 * of Unicode code points. Both evaluators run the vectors in tests/formats/format-vectors.json.
 */

const PATTERN_DELIMITER = '\u0001'
const ALLOWED_PATTERN_FLAGS = /^(?!.*(.).*\1)[ims]*$/
const compiledPatterns = new Map()

export class FormatRuleError extends Error {

	constructor(message, options) {
		super(message, options)
		this.name = 'FormatRuleError'
	}

}

/**
 * @param {string} pattern the pattern as written in the rule
 * @param {string} flags any of i, m and s
 * @return {RegExp}
 */
function compilePattern(pattern, flags) {
	const key = flags + PATTERN_DELIMITER + pattern
	if (!compiledPatterns.has(key)) {
		try {
			compiledPatterns.set(key, new RegExp(pattern, 'u' + flags))
		} catch (error) {
			throw new FormatRuleError('The pattern ' + pattern + ' cannot be used: ' + error.message, { cause: error })
		}
	}
	return compiledPatterns.get(key)
}

/**
 * Whether a string value matches an unanchored pattern. Values that are not strings never match.
 *
 * @param {unknown} value the value to test
 * @param {unknown} pattern the pattern, without delimiters
 * @param {unknown} flags any of i, m and s
 * @return {boolean}
 */
function matchPattern(value, pattern, flags = '') {
	if (typeof pattern !== 'string' || pattern.includes(PATTERN_DELIMITER)) {
		throw new FormatRuleError('match expects a pattern string')
	}
	if (typeof flags !== 'string' || !ALLOWED_PATTERN_FLAGS.test(flags)) {
		throw new FormatRuleError('match only accepts the flags i, m and s, each at most once')
	}
	if (typeof value !== 'string') {
		return false
	}
	return compilePattern(pattern, flags).test(value)
}

/**
 * @param {unknown} value the string to split
 * @return {string[]}
 */
function chars(value) {
	return typeof value === 'string' ? Array.from(value) : []
}

/**
 * Position of the needle in a string (counted in code points) or in a list, or -1.
 *
 * @param {unknown} haystack the string or list to search
 * @param {unknown} needle what to look for
 * @return {number}
 */
function indexOf(haystack, needle) {
	if (typeof haystack === 'string') {
		if (typeof needle !== 'string') {
			return -1
		}
		const position = haystack.indexOf(needle)
		return position === -1 ? -1 : Array.from(haystack.slice(0, position)).length
	}
	if (Array.isArray(haystack)) {
		return haystack.findIndex(item => item === needle)
	}
	return -1
}

/**
 * A negative start counts from the end; a negative length leaves that many code points off the end.
 * Sources other than strings and numbers count as an empty string.
 *
 * @param {unknown} source the string to cut
 * @param {unknown} start where to start
 * @param {unknown} length how many code points to take
 * @return {string}
 */
function substr(source, start = 0, length = null) {
	const characters = typeof source === 'string' || typeof source === 'number' ? Array.from(String(source)) : []
	const count = characters.length
	const startAt = Math.trunc(Number(start)) || 0
	const from = startAt < 0 ? Math.max(0, count + startAt) : Math.min(startAt, count)
	if (length === null || length === undefined) {
		return characters.slice(from).join('')
	}
	const take = Math.trunc(Number(length)) || 0
	const to = take < 0 ? count + take : from + take
	return characters.slice(from, Math.max(from, to)).join('')
}

jsonLogic.add_operation('match', matchPattern)
jsonLogic.add_operation('chars', chars)
jsonLogic.add_operation('indexOf', indexOf)
jsonLogic.add_operation('substr', substr)
// calls arbitrary methods of values, and the PHP evaluator has no counterpart
jsonLogic.rm_operation('method')

/**
 * Returns what the rule evaluates to, without reducing it to a boolean.
 *
 * @param {object} rule a JSON Logic rule
 * @param {unknown} value the value the rule reads as {"var": "value"}
 * @return {unknown}
 */
export function applyFormatRule(rule, value) {
	try {
		return jsonLogic.apply(rule, { value })
	} catch (error) {
		if (error instanceof FormatRuleError) {
			throw error
		}
		throw new FormatRuleError('The format rule cannot be evaluated: ' + error.message, { cause: error })
	}
}

/**
 * @param {object} rule a JSON Logic rule
 * @param {unknown} value the value to validate
 * @return {boolean}
 */
export function evaluateFormatRule(rule, value) {
	return jsonLogic.truthy(applyFormatRule(rule, value))
}
