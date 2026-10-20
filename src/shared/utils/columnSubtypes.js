/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Subtypes the app implements itself. They always win over a format with the same name.
const BUILT_IN_SUBTYPES = {
	text: ['line', 'long', 'rich', 'link'],
	number: ['', 'stars', 'progress'],
	selection: ['', 'multi', 'check'],
	datetime: ['', 'date', 'time'],
	usergroup: [''],
	relation: [''],
}

// The subtype a format subtype builds on, per column type that supports formats
const FORMAT_BASE_SUBTYPES = {
	text: 'line',
	number: '',
}

/**
 * The format a column's subtype names, which is any subtype of a text or number column that the
 * app does not implement itself. Mirrors Column::formatNameOf() on the server.
 *
 * @param {object} col the column as the API returns it
 * @return {string|null}
 */
export function getFormatName(col) {
	const subtype = col.subtype ?? ''
	if (!(col.type in FORMAT_BASE_SUBTYPES) || subtype === '' || BUILT_IN_SUBTYPES[col.type].includes(subtype)) {
		return null
	}
	return subtype
}

/**
 * The built-in subtype a column behaves as. Mirrors Column::getBaseSubtype() on the server.
 *
 * @param {object} col the column as the API returns it
 * @return {string}
 */
export function getBaseSubtype(col) {
	const subtype = col.subtype ?? ''
	if (BUILT_IN_SUBTYPES[col.type]?.includes(subtype)) {
		return subtype
	}
	return FORMAT_BASE_SUBTYPES[col.type] ?? subtype
}
