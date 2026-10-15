/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { NODE_TYPE_TABLE, NODE_TYPE_VIEW } from '../../shared/constants.ts'

/** Number of columns of a grid page. */
export const GRID_COLUMNS = 12

/** Smallest number of columns a widget spans. */
export const GRID_MIN_WIDTH = 2

/** Height of one grid row. In pixels on purpose: GridStack takes a number, not a CSS variable. */
export const GRID_CELL_HEIGHT = 80

/** Space between widgets in pixels: two grid baselines of 4px. */
export const GRID_MARGIN = 8

/** Below this canvas width in pixels the grid shows one column. */
export const GRID_BREAKPOINT_SINGLE = 560

/** Below this canvas width in pixels the grid shows six columns. */
export const GRID_BREAKPOINT_MEDIUM = 960

/**
 * Deep copy of plain data. A JSON round-trip also copies reactive proxies, which
 * structuredClone refuses.
 *
 * @param {object|Array|string|number|boolean|null|undefined} value plain data
 * @return {object|Array|string|number|boolean|null} the copy
 */
function copy(value) {
	return JSON.parse(JSON.stringify(value ?? null))
}

/**
 * @param {Array} widgetTypes the schemas loaded from the server
 * @param {string} type widget type
 * @return {object|null} the schema of that type, or null when the server does not know it
 */
export function findWidgetType(widgetTypes, type) {
	return widgetTypes.find(widgetType => widgetType.type === type) ?? null
}

/**
 * The configuration a new widget of this type starts with: every property at its default.
 *
 * @param {object} widgetType a schema from the server
 * @return {object} the configuration
 */
export function defaultConfiguration(widgetType) {
	return Object.fromEntries(Object.entries(widgetType?.configuration ?? {}).map(([name, property]) => [name, copy(property.default)]))
}

/**
 * The content a new widget of this type starts with: every property at its default.
 *
 * @param {object} widgetType a schema from the server
 * @return {object} the content
 */
export function defaultContent(widgetType) {
	return Object.fromEntries(Object.entries(widgetType?.properties ?? {}).map(([name, property]) => [name, copy(property.default)]))
}

/**
 * Deep copy of a grid so edits never touch the stored page until saved.
 *
 * @param {object} grid grid as built by pageToGrid
 * @return {{widgets: Array, layout: Array}} the copy
 */
export function cloneGrid(grid) {
	return {
		widgets: copy(grid?.widgets ?? []),
		layout: copy(grid?.layout ?? []),
	}
}

/**
 * The row below the lowest widget, where a new widget is placed.
 *
 * @param {Array} layout layout items
 * @return {number} the row
 */
export function nextFreeRow(layout) {
	return layout.reduce((max, item) => Math.max(max, (item.gridY ?? 0) + (item.gridHeight ?? 1)), 0)
}

/**
 * How many columns the grid shows on a canvas of this width: 12 on a wide one, 6 on a
 * medium one and one on a phone.
 *
 * @param {number} width width of the canvas in pixels
 * @return {number} the number of columns
 */
export function columnsForWidth(width) {
	if (width < GRID_BREAKPOINT_SINGLE) {
		return 1
	}
	return width < GRID_BREAKPOINT_MEDIUM ? 6 : GRID_COLUMNS
}

/**
 * The layout with the positions of the GridStack nodes that changed applied to it.
 *
 * @param {Array} layout layout items
 * @param {Array<{id: string, x: number, y: number, w: number, h: number}>} nodes the changed nodes
 * @return {Array} the updated layout
 */
export function applyNodePositions(layout, nodes) {
	const positions = Object.fromEntries(nodes.map(node => [node.id, node]))
	return layout.map(item => {
		const node = positions[item.widgetId]
		return node ? { ...item, gridX: node.x, gridY: node.y, gridWidth: node.w, gridHeight: node.h } : item
	})
}

/**
 * The table or view a node relation of the application points at.
 *
 * @param {object|undefined} node a node of the application, as in context.nodes
 * @return {{nodeType: number, nodeId: number}|null} the node, or null for an unknown one
 */
function nodeOf(node) {
	if (!node) {
		return null
	}
	const nodeType = parseInt(node.node_type)
	return nodeType === NODE_TYPE_TABLE || nodeType === NODE_TYPE_VIEW ? { nodeType, nodeId: parseInt(node.node_id) } : null
}

/**
 * The grid of a page: its widgets in order and where they sit. A data widget carries the
 * node relation it is stored with and the table or view behind it.
 *
 * @param {object} page a page as the API returns it
 * @param {object} nodes the nodes of the application by relation id
 * @return {{widgets: Array, layout: Array}} the grid
 */
export function pageToGrid(page, nodes) {
	const content = Object.values(page?.content ?? {})
		.sort((a, b) => (a.order - b.order) || (a.id - b.id))
	return {
		widgets: content.map(entry => ({
			id: 'c' + entry.id,
			type: entry.type,
			nodeRelId: entry.node_rel_id ?? null,
			node: entry.node_rel_id ? nodeOf(nodes?.[entry.node_rel_id]) : null,
			configuration: copy(entry.configuration ?? {}),
			content: Array.isArray(entry.content) ? {} : copy(entry.content ?? {}),
		})),
		layout: content.map(entry => ({
			widgetId: 'c' + entry.id,
			gridX: entry.grid_x,
			gridY: entry.grid_y,
			gridWidth: entry.grid_width,
			gridHeight: entry.grid_height,
		})),
	}
}

/**
 * The table or view a data widget shows.
 *
 * @param {object} widget a widget of the grid
 * @return {{type: string, id: number}|null} the target, or null when it has none
 */
export function widgetTarget(widget) {
	if (!widget?.node) {
		return null
	}
	return { type: widget.node.nodeType === NODE_TYPE_VIEW ? 'view' : 'table', id: widget.node.nodeId }
}

/**
 * The widgets of a grid as the API saves them. A data widget names the node relation of
 * the application that points at its table or view, or the table or view itself when the
 * application does not have it yet.
 *
 * @param {{widgets: Array, layout: Array}} grid the grid
 * @param {object} nodes the nodes of the application by relation id
 * @return {Array} the widgets to save
 */
export function gridToWidgets(grid, nodes) {
	const positions = Object.fromEntries(grid.layout.map(item => [item.widgetId, item]))
	return grid.widgets.filter(widget => positions[widget.id]).map(widget => {
		const { gridX, gridY, gridWidth, gridHeight } = positions[widget.id]
		const saved = {
			type: widget.type,
			configuration: copy(widget.configuration ?? {}),
			content: copy(widget.content ?? {}),
			gridX,
			gridY,
			gridWidth,
			gridHeight,
		}
		if (widget.node) {
			const relation = Object.values(nodes ?? {}).find(node => {
				const known = nodeOf(node)
				return known?.nodeType === widget.node.nodeType && known?.nodeId === widget.node.nodeId
			})
			if (relation) {
				saved.nodeRelId = parseInt(relation.id)
			} else {
				saved.node = { ...widget.node }
			}
		} else if (widget.nodeRelId) {
			saved.nodeRelId = widget.nodeRelId
		}
		return saved
	})
}
