/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getCurrentUser } from '@nextcloud/auth'

/** Number of columns of the page grid. */
export const GRID_COLUMNS = 12

/** Column spans a widget can take in a personal layout. */
export const GRID_SPANS = [12, 8, 6, 4]

/**
 * @param {number|string} contextId id of the application
 * @param {number|string} pageId id of the page
 * @return {string}
 */
function getStorageKey(contextId, pageId) {
	return 'tables:context-layout:' + (getCurrentUser()?.uid ?? '') + ':' + contextId + ':' + pageId
}

/**
 * The current user's widths of the widgets on a page of an application, as a map of widget id to
 * column span. Browser storage can be unavailable (private windows, blocked site data); the page
 * then shows as it was arranged.
 *
 * @param {number|string} contextId id of the application
 * @param {number|string} pageId id of the page
 * @return {Object<string, number>}
 */
export function loadContextLayout(contextId, pageId) {
	try {
		const storedLayout = JSON.parse(window.localStorage.getItem(getStorageKey(contextId, pageId)) ?? '{}')
		return Object.fromEntries(Object.entries(storedLayout ?? {})
			.filter(([, span]) => GRID_SPANS.includes(span)))
	} catch (error) {
		console.warn('Application layout could not be read, using the default', error)
		return {}
	}
}

/**
 * Stores the current user's widths in this browser. Throws when the browser refuses it, so the
 * caller can tell the user that the layout will not be kept.
 *
 * @param {number|string} contextId id of the application
 * @param {number|string} pageId id of the page
 * @param {Object<string, number>} layout map of widget id to column span; an empty map resets to the page as arranged
 */
export function saveContextLayout(contextId, pageId, layout) {
	if (Object.keys(layout).length === 0) {
		window.localStorage.removeItem(getStorageKey(contextId, pageId))
		return
	}
	window.localStorage.setItem(getStorageKey(contextId, pageId), JSON.stringify(layout))
}

/**
 * The layout of a page with the user's own widths applied. A widget that would no longer fit
 * moves left; the grid moves widgets down where they would overlap.
 *
 * @param {Array} layout the layout items of the page as arranged
 * @param {Object<string, number>} widths map of widget id to column span
 * @return {Array} the layout to show
 */
export function applyPersonalWidths(layout, widths) {
	return layout.map(item => {
		const span = widths[item.widgetId]
		if (!span) {
			return item
		}
		return { ...item, gridWidth: span, gridX: Math.min(item.gridX, GRID_COLUMNS - span) }
	})
}
