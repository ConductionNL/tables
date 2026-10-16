/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Locator, Page } from '@playwright/test'
import { test, expect } from '../support/fixtures'
import { ensureNavigationOpen } from '../support/commands'

type MenuItem = { label: string, targetType: string, targetId: number | null, technicalName: string }

/**
 * Opens the create application dialog.
 *
 * @param page the page of the signed in user
 * @return the dialog
 */
async function openCreateDialog(page: Page): Promise<Locator> {
	await page.goto('/index.php/apps/tables')
	await ensureNavigationOpen(page)
	await page.getByRole('button', { name: /^Create application$/ }).first().click()
	const modal = page.locator('[data-cy="createContextModal"]')
	await expect(modal).toBeVisible()
	return modal
}

/**
 * Submits the create application dialog.
 *
 * @param page the page of the signed in user
 * @param title the title of the new application
 * @return the created application as the API returns it
 */
async function createApplication(page: Page, title: string) {
	await page.locator('[data-cy="createContextTitle"]').fill(title)
	const created = page.waitForResponse(response => response.url().includes('/api/2/contexts') && response.request().method() === 'POST')
	await page.locator('[data-cy="createContextSubmitBtn"]').click()
	const context = (await (await created).json()).ocs.data
	const startPageId = Number(Object.keys(context.pages).find(id => context.pages[id].pageType === 'startpage'))
	const menu = context.menuItems.map((item: MenuItem) => [item.label, item.targetType, item.targetId, item.technicalName])
	return { context, startPageId, menu }
}

test.describe('Application menu', () => {
	test('A new application gets a dashboard item that opens its start page', async ({
		userPage: { page },
	}) => {
		const contextTitle = 'test application dashboard'
		const modal = await openCreateDialog(page)

		// The dashboard item comes first; it opens the start page and can be renamed and moved
		const rows = modal.locator('[data-cy="menuItemRow"]')
		const firstRow = rows.first()
		await expect(firstRow.getByRole('textbox', { name: 'Label' })).toHaveValue('Dashboard')
		await expect(firstRow.getByRole('combobox', { name: 'Opens' })).toBeDisabled()
		await expect(firstRow.locator('[data-cy="menuItemRemove"]')).toBeVisible()
		await firstRow.getByRole('textbox', { name: 'Label' }).fill('Overview')

		await modal.locator('[data-cy="menuItemAdd"]').click()
		const secondRow = rows.nth(1)
		await secondRow.getByRole('textbox', { name: 'Label' }).fill('Docs')
		await secondRow.getByRole('textbox', { name: 'Link' }).fill('https://example.org/docs')
		await secondRow.locator('[data-cy="menuItemMoveUp"]').click()
		await expect(rows.first().getByRole('textbox', { name: 'Label' })).toHaveValue('Docs')

		const { context, startPageId, menu } = await createApplication(page, contextTitle)
		expect(menu).toEqual([
			['Docs', 'url', null, 'docs'],
			['Overview', 'page', startPageId, 'dashboard'],
		])
		expect(context.menuItems[1].broken).toBe(false)

		// The dashboard item opens the start page of the application
		await page.goto('/index.php/apps/tables/#/application/' + context.id)
		await expect(page.locator('h1').filter({ hasText: contextTitle }).first()).toBeVisible()
	})

	test('The dashboard item can be removed in the dialog', async ({
		userPage: { page },
	}) => {
		const modal = await openCreateDialog(page)
		const rows = modal.locator('[data-cy="menuItemRow"]')
		await expect(rows).toHaveCount(1)
		await rows.first().locator('[data-cy="menuItemRemove"]').click()
		await expect(rows).toHaveCount(0)

		// The menu is stored as submitted, and the application still has its start page
		const contextTitle = 'test application without dashboard'
		const { context, startPageId, menu } = await createApplication(page, contextTitle)
		expect(menu).toEqual([])
		expect(startPageId).toBeGreaterThan(0)

		await page.goto('/index.php/apps/tables/#/application/' + context.id)
		await expect(page.locator('h1').filter({ hasText: contextTitle }).first()).toBeVisible()
	})
})
