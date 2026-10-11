/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Menu items as the API stores them, with a stable key for list rendering while editing.
 *
 * @param {Array|undefined|null} menuItems items from the application
 * @return {Array}
 */
export function toEditableMenuItems(menuItems) {
	return (menuItems ?? []).map((item, index) => ({
		key: 'item-' + (item.id ?? index),
		label: item.label ?? '',
		icon: item.icon ?? null,
		targetType: item.targetType ?? 'url',
		targetId: item.targetId ?? null,
		url: item.url ?? '',
		technicalName: item.technicalName ?? null,
		parentKey: item.parentId != null ? 'item-' + item.parentId : null,
	}))
}

/**
 * The payload the API expects for one menu item.
 *
 * @param {object} item an item from the editor
 * @param {Array} items the whole editable list, to turn the parent key into the parent's position
 * @return {object}
 */
export function toMenuItemPayload(item, items) {
	const parentIndex = item.parentKey ? items.findIndex(candidate => candidate.key === item.parentKey) : -1
	return {
		label: item.label,
		icon: item.icon || null,
		targetType: item.targetType,
		targetId: item.targetType === 'url' ? null : item.targetId,
		url: item.targetType === 'url' ? item.url : null,
		technicalName: item.technicalName || null,
		parentIndex: parentIndex >= 0 ? parentIndex : null,
	}
}

/**
 * Where a menu item leads inside the app, or null for an external link.
 *
 * @param {object} item a menu item
 * @param {number|string} contextId the application the item belongs to
 * @return {string|null}
 */
export function menuItemRoute(item, contextId) {
	if (item.targetType === 'url') {
		return null
	}
	return '/application/' + contextId + '/menu/' + item.technicalName
}

/**
 * The menu as a tree: top-level items with their children, in menu order.
 *
 * @param {Array} menuItems stored items with id, order and parentId
 * @return {Array<{item: object, children: Array<object>}>}
 */
export function menuTree(menuItems) {
	const sorted = [...(menuItems ?? [])].sort((a, b) => a.order - b.order)
	const roots = sorted.filter(item => item.parentId == null)
	return roots.map(item => ({ item, children: sorted.filter(child => child.parentId === item.id) }))
}
