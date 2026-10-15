/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import {
	areMenuItemsValid,
	canIndent,
	canMove,
	dragItemOver,
	indentItem,
	isFixedItem,
	isValidMenuLink,
	menuItemErrors,
	moveItem,
	newDashboardItem,
	outdentItem,
	parentOfPosition,
	removeItem,
	toMenuItemPayload,
} from '../../src/shared/utils/menuItems.js'

/**
 * An item of the editor's list.
 *
 * @param {string} key the item's key, also used as its label
 * @param {string|null} parentKey the key of its parent, or null at the top level
 * @return {object} the item
 */
function item(key, parentKey = null) {
	return { key, label: key, icon: null, targetType: 'url', targetId: null, url: 'https://example.org/' + key, technicalName: null, parentKey }
}

/**
 * The list as "key" for a top-level item and "parent>key" for a child, to read the shape at a glance.
 *
 * @param {Array<object>} items the list
 * @return {Array<string>} the shape of the list
 */
function shape(items) {
	return items.map(entry => entry.parentKey === null ? entry.key : entry.parentKey + '>' + entry.key)
}

// A with its child a1, then B and C at the top level
const menu = () => [item('A'), item('a1', 'A'), item('B'), item('C')]

describe('menu items', () => {
	it('sends a child with the position of its parent', () => {
		const items = menu()

		expect(toMenuItemPayload(items[1], items).parentIndex).to.equal(0)
		expect(toMenuItemPayload(items[2], items).parentIndex).to.equal(null)
		expect(toMenuItemPayload(items[2], items).targetId).to.equal(null)
	})

	it('accepts only the links the server accepts', () => {
		expect(isValidMenuLink('https://example.org/docs')).to.equal(true)
		expect(isValidMenuLink('http://example.org')).to.equal(true)
		expect(isValidMenuLink('www.example.org')).to.equal(false)
		expect(isValidMenuLink('https://')).to.equal(false)
		expect(isValidMenuLink('javascript://example.com/%0Aalert(1)')).to.equal(false)
		expect(isValidMenuLink('https://example.org/' + 'x'.repeat(2000))).to.equal(false)
	})

	it('marks an item without a label, without a target or with a bad link', () => {
		expect(menuItemErrors({ ...item('A'), label: '  ' }).label).to.equal(true)
		expect(menuItemErrors({ ...item('A'), targetType: 'view', targetId: null }).target).to.equal(true)
		expect(menuItemErrors({ ...item('A'), url: 'www.example.org' }).url).to.equal(true)
		expect(menuItemErrors(item('A'))).to.deep.equal({ label: false, target: false, url: false })
		expect(areMenuItemsValid(menu())).to.equal(true)
		expect(areMenuItemsValid([...menu(), { ...item('D'), url: '' }])).to.equal(false)
	})

	it('moves a top-level item together with its children', () => {
		expect(shape(moveItem(menu(), 2, -1))).to.deep.equal(['B', 'A', 'A>a1', 'C'])
		expect(shape(moveItem(menu(), 0, 1))).to.deep.equal(['B', 'A', 'A>a1', 'C'])
		expect(canMove(menu(), 0, -1)).to.equal(false)
		expect(canMove(menu(), 1, 1)).to.equal(false, 'a1 is the only child of A')
	})

	it('nests one level deep and only under the top-level item above', () => {
		expect(canIndent(menu(), 0)).to.equal(false, 'nothing above A')
		expect(canIndent(menu(), 2)).to.equal(true)
		expect(shape(indentItem(menu(), 2))).to.deep.equal(['A', 'A>a1', 'A>B', 'C'])
		expect(canIndent([item('A'), item('B'), item('b1', 'B')], 1)).to.equal(false, 'B has a child itself')
	})

	it('puts an outdented child after the last child of its parent', () => {
		const items = [item('A'), item('a1', 'A'), item('a2', 'A'), item('B')]

		expect(shape(outdentItem(items, 1))).to.deep.equal(['A', 'A>a2', 'a1', 'B'])
	})

	it('moves the children of a removed item to the top level', () => {
		expect(shape(removeItem(menu(), 0))).to.deep.equal(['a1', 'B', 'C'])
		expect(shape(removeItem(menu(), 1))).to.deep.equal(['A', 'B', 'C'])
	})

	it('knows which parent a position belongs to', () => {
		const rest = [item('A'), item('a1', 'A'), item('B')]

		expect(parentOfPosition(rest, 0)).to.equal(null)
		expect(parentOfPosition(rest, 1)?.key).to.equal('A')
		expect(parentOfPosition(rest, 2)?.key).to.equal('A')
		expect(parentOfPosition(rest, 3)).to.equal(null)
	})

	it('drags a child into another parent and a top-level block past children', () => {
		const items = [item('A'), item('a1', 'A'), item('B'), item('b1', 'B')]

		const childMoved = dragItemOver(items, 1, 3)
		expect(shape(childMoved.items)).to.deep.equal(['A', 'B', 'B>b1', 'B>a1'])
		expect(childMoved.draggedIndex).to.equal(3)

		const blockMoved = dragItemOver(items, 2, 1)
		expect(shape(blockMoved.items)).to.deep.equal(['A', 'A>a1', 'B', 'B>b1'], 'B cannot land between A and its child')

		expect(dragItemOver(items, 0, 1)).to.equal(null, 'a block cannot be dropped on its own child')
		expect(dragItemOver(items, 2, 2)).to.equal(null)
	})

	it('keeps the dashboard item first, fixed and pointing at the new start page', () => {
		const dashboard = newDashboardItem('Dashboard')
		const items = [dashboard, item('A'), item('B')]

		expect(isFixedItem(dashboard)).to.equal(true)
		expect(menuItemErrors(dashboard)).to.deep.equal({ label: false, target: false, url: false })
		expect(menuItemErrors({ ...dashboard, label: ' ' }).label).to.equal(true)
		expect(areMenuItemsValid(items)).to.equal(true)

		const payload = toMenuItemPayload({ ...dashboard, label: 'Overview' }, items)
		expect(payload.targetType).to.equal('page')
		expect(payload.targetId).to.equal(null)
		expect(payload.label).to.equal('Overview')
		expect(payload.technicalName).to.equal('dashboard')

		expect(removeItem(items, 0)).to.equal(items)
		expect(canMove(items, 0, 1)).to.equal(false)
		expect(canMove(items, 1, -1)).to.equal(false)
		expect(moveItem(items, 1, -1)).to.equal(items)
		expect(canIndent(items, 0)).to.equal(false)
		expect(dragItemOver(items, 0, 2)).to.equal(null)
		expect(dragItemOver(items, 2, 0)).to.equal(null)
		expect(shape(dragItemOver(items, 2, 1).items)).to.deep.equal(['dashboard', 'B', 'A'])
	})

	it('nests items under the dashboard item like under any other', () => {
		const items = [newDashboardItem('Dashboard'), item('A')]

		const nested = indentItem(items, 1)
		expect(shape(nested)).to.deep.equal(['dashboard', 'dashboard>A'])
		expect(toMenuItemPayload(nested[1], nested).parentIndex).to.equal(0)
		expect(shape(outdentItem(nested, 1))).to.deep.equal(['dashboard', 'A'])
	})

	it('passes a page item from the server through as it is', () => {
		const page = { key: 'p', label: 'Overview', icon: null, targetType: 'page', targetId: 12, url: null, technicalName: 'overview', parentKey: null }
		const items = [page, item('A')]

		expect(menuItemErrors(page)).to.deep.equal({ label: false, target: false, url: false })
		expect(areMenuItemsValid(items)).to.equal(true)
		expect(toMenuItemPayload(page, items)).to.deep.equal({
			label: 'Overview',
			icon: null,
			targetType: 'page',
			targetId: 12,
			url: null,
			technicalName: 'overview',
			parentIndex: null,
		})
		expect(isFixedItem(page)).to.equal(false)
		expect(shape(moveItem(items, 0, 1))).to.deep.equal(['A', 'p'])
		// without an id, only the fixed dashboard item is valid
		expect(menuItemErrors({ ...page, targetId: null }).target).to.equal(true)
	})
})
