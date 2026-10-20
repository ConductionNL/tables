/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { loadState } from '@nextcloud/initial-state'

/*
 * The enabled formats the page was rendered with. Where Tables is embedded in another app there are
 * none, so formatted columns behave as their base subtype and only the server validates them.
 */

let definitionsByName = null

/**
 * @param {string} technicalName the format a column subtype names
 * @return {object|null}
 */
export function getFormatDefinition(technicalName) {
	if (definitionsByName === null) {
		const definitions = loadState('tables', 'formats', [])
		definitionsByName = new Map(definitions.map(definition => [definition.technicalName, definition]))
	}
	return definitionsByName.get(technicalName) ?? null
}
