/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { reactive } from 'vue'
import {
	GRID_BREAKPOINT_MEDIUM,
	GRID_BREAKPOINT_SINGLE,
	GRID_COLUMNS,
	applyNodePositions,
	cloneGrid,
	columnsForWidth,
	defaultConfiguration,
	defaultContent,
	gridToWidgets,
	nextFreeRow,
	pageToGrid,
	widgetTarget,
} from '../../src/modules/grid/gridLayout.js'
import { isHexColor } from '../../src/shared/utils/gridColors.js'

const headerType = {
	type: 'header',
	configuration: {
		title: { type: 'string', default: '' },
		showTitle: { type: 'boolean', default: false },
	},
	properties: {
		title: { type: 'string', default: '' },
		textAlign: { type: 'enum', default: 'left' },
	},
}

const nodes = {
	4: { id: 4, node_type: 0, node_id: 40, permissions: 1 },
	5: { id: 5, node_type: 1, node_id: 50, permissions: 1 },
}

const startPage = {
	id: 1,
	page_type: 'startpage',
	content: {
		12: { id: 12, order: 20, node_rel_id: 5, type: 'data', configuration: { title: '', showTitle: false }, content: [], grid_x: 0, grid_y: 5, grid_width: 12, grid_height: 5 },
		11: { id: 11, order: 10, node_rel_id: 4, type: 'data', configuration: { title: '', showTitle: false }, content: [], grid_x: 0, grid_y: 0, grid_width: 12, grid_height: 5 },
		13: { id: 13, order: 30, node_rel_id: null, type: 'header', configuration: { title: '', showTitle: false }, content: { title: 'Hi' }, grid_x: 0, grid_y: 10, grid_width: 12, grid_height: 2 },
	},
}

describe('grid layout', () => {
	it('starts a widget with every configuration and content property at its default', () => {
		expect(defaultConfiguration(headerType)).to.deep.equal({ title: '', showTitle: false })
		expect(defaultContent(headerType)).to.deep.equal({ title: '', textAlign: 'left' })
		expect(defaultContent(null)).to.deep.equal({})
	})

	it('copies a grid deeply, also when it is reactive', () => {
		const stored = reactive({ widgets: [{ id: 'w-1', content: { title: 'Hi' } }], layout: [{ widgetId: 'w-1', gridX: 0 }] })

		const copy = cloneGrid(stored)
		copy.widgets[0].content.title = 'Changed'
		copy.layout[0].gridX = 4

		expect(stored.widgets[0].content.title).to.equal('Hi')
		expect(stored.layout[0].gridX).to.equal(0)
		expect(cloneGrid(undefined)).to.deep.equal({ widgets: [], layout: [] })
	})

	it('reads a page into widgets in order with the table or view of each data widget', () => {
		const grid = pageToGrid(startPage, nodes)

		expect(grid.widgets.map(widget => widget.id)).to.deep.equal(['c11', 'c12', 'c13'])
		expect(grid.widgets[0]).to.deep.include({ type: 'data', nodeRelId: 4, node: { nodeType: 0, nodeId: 40 }, content: {} })
		expect(grid.widgets[1].node).to.deep.equal({ nodeType: 1, nodeId: 50 })
		expect(grid.widgets[2]).to.deep.include({ type: 'header', nodeRelId: null, node: null, content: { title: 'Hi' } })
		expect(grid.layout[1]).to.deep.equal({ widgetId: 'c12', gridX: 0, gridY: 5, gridWidth: 12, gridHeight: 5 })
		expect(widgetTarget(grid.widgets[0])).to.deep.equal({ type: 'table', id: 40 })
		expect(widgetTarget(grid.widgets[1])).to.deep.equal({ type: 'view', id: 50 })
		expect(widgetTarget(grid.widgets[2])).to.equal(null)
	})

	it('marks a data widget whose node is gone as without a table or view', () => {
		const grid = pageToGrid({ content: [{ id: 1, order: 10, node_rel_id: 9, type: 'data', configuration: {}, content: [], grid_x: 0, grid_y: 0, grid_width: 12, grid_height: 5 }] }, nodes)

		expect(grid.widgets[0].node).to.equal(null)
		expect(widgetTarget(grid.widgets[0])).to.equal(null)
	})

	it('saves data widgets by node relation when the application has the node, else by the node', () => {
		const grid = pageToGrid(startPage, nodes)
		grid.widgets.push({ id: 'new-1', type: 'data', nodeRelId: null, node: { nodeType: 0, nodeId: 70 }, configuration: { title: 'New', showTitle: true }, content: {} })
		grid.layout.push({ widgetId: 'new-1', gridX: 6, gridY: 12, gridWidth: 6, gridHeight: 4 })
		grid.widgets.push({ id: 'new-2', type: 'data', nodeRelId: null, node: { nodeType: 1, nodeId: 50 }, configuration: {}, content: {} })
		grid.layout.push({ widgetId: 'new-2', gridX: 0, gridY: 12, gridWidth: 6, gridHeight: 4 })
		grid.widgets.push({ id: 'unplaced', type: 'text', configuration: {}, content: {} })

		const widgets = gridToWidgets(grid, nodes)

		expect(widgets).to.have.length(5)
		expect(widgets[0]).to.deep.equal({ type: 'data', configuration: { title: '', showTitle: false }, content: {}, gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 5, nodeRelId: 4 })
		expect(widgets[2]).to.not.have.property('nodeRelId')
		expect(widgets[2]).to.not.have.property('node')
		expect(widgets[3]).to.deep.include({ node: { nodeType: 0, nodeId: 70 }, gridX: 6, gridWidth: 6 })
		expect(widgets[3]).to.not.have.property('nodeRelId')
		expect(widgets[4]).to.deep.include({ nodeRelId: 5 })
	})

	it('places a new widget below the lowest one', () => {
		expect(nextFreeRow([])).to.equal(0)
		expect(nextFreeRow([{ gridY: 0, gridHeight: 2 }, { gridY: 3, gridHeight: 4 }, { gridY: 1 }])).to.equal(7)
	})

	it('shows 12, 6 or 1 columns by canvas width', () => {
		expect(columnsForWidth(GRID_BREAKPOINT_SINGLE - 1)).to.equal(1)
		expect(columnsForWidth(GRID_BREAKPOINT_SINGLE)).to.equal(6)
		expect(columnsForWidth(GRID_BREAKPOINT_MEDIUM - 1)).to.equal(6)
		expect(columnsForWidth(GRID_BREAKPOINT_MEDIUM)).to.equal(GRID_COLUMNS)
	})

	it('applies only the positions of the nodes that changed', () => {
		const layout = [
			{ id: 'w-1', widgetId: 'w-1', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 2 },
			{ id: 'w-2', widgetId: 'w-2', gridX: 6, gridY: 0, gridWidth: 6, gridHeight: 2 },
		]

		const updated = applyNodePositions(layout, [{ id: 'w-2', x: 0, y: 2, w: 12, h: 3 }])

		expect(updated[0]).to.equal(layout[0])
		expect(updated[1]).to.deep.equal({ id: 'w-2', widgetId: 'w-2', gridX: 0, gridY: 2, gridWidth: 12, gridHeight: 3 })
	})

	it('accepts only hex colors, like the server', () => {
		expect(isHexColor('#abc')).to.equal(true)
		expect(isHexColor('#a1b2c3')).to.equal(true)
		expect(isHexColor('red')).to.equal(false)
		expect(isHexColor('url(evil)')).to.equal(false)
		expect(isHexColor(null)).to.equal(false)
	})
})
