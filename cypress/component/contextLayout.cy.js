/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { applyPersonalWidths, loadContextLayout, saveContextLayout } from '../../src/shared/utils/contextLayout.js'

describe('application layout', () => {
	beforeEach(() => {
		window.localStorage.clear()
	})

	it('keeps a saved layout per application and page', () => {
		saveContextLayout(7, 3, { 'content-4': 6, 'content-9': 4 })

		expect(loadContextLayout(7, 3)).to.deep.equal({ 'content-4': 6, 'content-9': 4 })
		expect(loadContextLayout(7, 5)).to.deep.equal({})
		expect(loadContextLayout(8, 3)).to.deep.equal({})
	})

	it('resets to the page as arranged when an empty layout is saved', () => {
		saveContextLayout(7, 3, { 'content-4': 6 })
		saveContextLayout(7, 3, {})

		expect(loadContextLayout(7, 3)).to.deep.equal({})
	})

	it('drops widths it does not know and survives a broken entry', () => {
		saveContextLayout(7, 3, { a: 6, b: 5, c: '12' })
		expect(loadContextLayout(7, 3)).to.deep.equal({ a: 6 })

		const key = Object.keys(window.localStorage).find(name => name.endsWith(':7:3'))
		window.localStorage.setItem(key, '{not json')
		expect(loadContextLayout(7, 3)).to.deep.equal({})
	})

	it('applies the widths to the layout and keeps a widened widget on the grid', () => {
		const layout = [
			{ widgetId: 'a', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 5 },
			{ widgetId: 'b', gridX: 8, gridY: 5, gridWidth: 4, gridHeight: 5 },
		]

		const shown = applyPersonalWidths(layout, { a: 6, b: 8 })

		expect(shown[0]).to.deep.include({ gridX: 0, gridWidth: 6 })
		expect(shown[1]).to.deep.include({ gridX: 4, gridWidth: 8 })
		expect(applyPersonalWidths(layout, {})).to.deep.equal(layout)
	})
})
