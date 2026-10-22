/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import GridWidget from '../../src/modules/grid/GridWidget.vue'

const nodes = { 4: { id: 4, node_type: 0, node_id: 40, permissions: 1 } }

/**
 * @param {object} widget the widget to show
 * @param {boolean} editable whether the page is edited
 */
function mountWidget(widget, editable) {
	cy.intercept('GET', '**/apps/tables/api/2/widget-types', { ocs: { data: [] } })
	cy.mount(GridWidget, { props: { widget, nodes, editable } })
}

describe('GridWidget', () => {
	it('shows the title of a widget that has one', () => {
		mountWidget({ key: 'w1', id: 1, type: 'header', title: 'Welcome', settings: { heading: 'Projects', subheading: 'This quarter' }, nodeRelId: null }, false)

		cy.get('[data-cy="gridWidget"] h2').should('contain.text', 'Welcome')
		cy.get('[data-cy="gridWidget"]').should('contain.text', 'Projects').and('contain.text', 'This quarter')
	})

	it('shows no heading for a widget without a title outside edit mode', () => {
		mountWidget({ key: 'w2', id: 2, type: 'text', title: '', settings: { text: 'First\n\nSecond' }, nodeRelId: null }, false)

		cy.get('[data-cy="gridWidget"] h2').should('not.exist')
		cy.get('.text-widget__paragraph').should('have.length', 2)
	})

	it('asks the editor to pick a table or view when a data widget has none', () => {
		mountWidget({ key: 'w3', id: 3, type: 'data', title: '', settings: {}, nodeRelId: 9 }, true)

		cy.get('[data-cy="dataWidgetPickSource"]').should('be.visible')
		cy.get('[data-cy="dataWidgetNoSource"]').should('not.exist')
	})

	it('shows an empty state to viewers when a data widget has no table or view', () => {
		mountWidget({ key: 'w4', id: 4, type: 'data', title: 'Tasks', settings: {}, nodeRelId: null }, false)

		cy.get('[data-cy="dataWidgetNoSource"]').should('contain.text', 'No table or view to show')
		cy.get('[data-cy="dataWidgetPickSource"]').should('not.exist')
	})
})
