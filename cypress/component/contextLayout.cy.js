/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getGridCellStyle, loadContextLayout, saveContextLayout } from '../../src/shared/utils/contextLayout.js'

describe('application layout', () => {
	beforeEach(() => {
		window.localStorage.clear()
	})

	it('keeps a saved layout per application', () => {
		saveContextLayout(7, { 4: 6, 'view-9': 4 })

		expect(loadContextLayout(7)).to.deep.equal({ 4: 6, 'view-9': 4 })
		expect(loadContextLayout(8)).to.deep.equal({})
	})

	it('resets to the default when an empty layout is saved', () => {
		saveContextLayout(7, { 4: 6 })
		saveContextLayout(7, {})

		expect(loadContextLayout(7)).to.deep.equal({})
	})

	it('drops widths it does not know and survives a broken entry', () => {
		saveContextLayout(7, { 4: 6, 5: 5, 6: '12' })
		expect(loadContextLayout(7)).to.deep.equal({ 4: 6 })

		const key = Object.keys(window.localStorage).find(name => name.endsWith(':7'))
		window.localStorage.setItem(key, '{not json')
		expect(loadContextLayout(7)).to.deep.equal({})
	})

	it('narrows a wide item to the medium grid', () => {
		expect(getGridCellStyle(8)).to.deep.equal({ '--context-grid-span': 8, '--context-grid-span-medium': 6 })
		expect(getGridCellStyle(4)).to.deep.equal({ '--context-grid-span': 4, '--context-grid-span-medium': 4 })
	})
})
