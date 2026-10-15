/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { test, expect } from '../support/fixtures'
import { ensureNavigationOpen } from '../support/commands'

test.describe('Application menu', () => {
	test('A new application gets a dashboard item that opens its start page', async ({
		userPage: { page },
	}) => {
		const contextTitle = 'test application dashboard'
		await page.goto('/index.php/apps/tables')
		await ensureNavigationOpen(page)
		await page.getByRole('button', { name: /^Create application$/ }).first().click()
		const modal = page.locator('[data-cy="createContextModal"]')
		await expect(modal).toBeVisible()

		// The dashboard item comes first, can be renamed, and cannot be removed or retargeted
		const firstRow = modal.locator('[data-cy="menuItemRow"]').first()
		await expect(firstRow.getByRole('textbox', { name: 'Label' })).toHaveValue('Dashboard')
		await expect(firstRow.locator('[data-cy="menuItemRemove"]')).toHaveCount(0)
		await expect(firstRow.locator('[data-cy="menuItemMoveDown"]')).toBeDisabled()
		await expect(firstRow.getByRole('combobox', { name: 'Opens' })).toBeDisabled()

		await modal.locator('[data-cy="menuItemAdd"]').click()
		const secondRow = modal.locator('[data-cy="menuItemRow"]').nth(1)
		await secondRow.getByRole('textbox', { name: 'Label' }).fill('Docs')
		await secondRow.getByRole('textbox', { name: 'Link' }).fill('https://example.org/docs')
		await expect(secondRow.locator('[data-cy="menuItemMoveUp"]')).toBeDisabled()

		await page.locator('[data-cy="createContextTitle"]').fill(contextTitle)
		const created = page.waitForResponse(response => response.url().includes('/api/2/contexts') && response.request().method() === 'POST')
		await page.locator('[data-cy="createContextSubmitBtn"]').click()
		const context = (await (await created).json()).ocs.data

		const startPageId = Number(Object.keys(context.pages).find(id => context.pages[id].pageType === 'startpage'))
		expect(context.menuItems.map((item: { label: string, targetType: string, targetId: number | null, technicalName: string }) => [item.label, item.targetType, item.targetId, item.technicalName]))
			.toEqual([
				['Dashboard', 'page', startPageId, 'dashboard'],
				['Docs', 'url', null, 'docs'],
			])
		expect(context.menuItems[0].broken).toBe(false)

		// The dashboard item opens the start page of the application
		await page.goto('/index.php/apps/tables/#/application/' + context.id)
		await expect(page.locator('h1').filter({ hasText: contextTitle }).first()).toBeVisible()
	})
})
