/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Page } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { test, expect } from '../support/fixtures'
import { ocsRequest, type TestUser } from '../support/api'
import { createContext, ensureNavigationOpen, loadContext } from '../support/commands'

/**
 * Creates a grid view and an application whose menu opens it, through the API.
 *
 * @param page the page of the test user
 * @param user the test user
 * @param name name of the application
 * @param pageTitle title of the grid view, and label of its menu item
 */
async function createApplicationWithGridPage(page: Page, user: TestUser, name: string, pageTitle: string) {
	const viewResponse = await ocsRequest(page.request, user, {
		method: 'POST',
		url: '/ocs/v2.php/apps/tables/api/2/views?format=json',
		data: { title: pageTitle },
	})
	const view = (await viewResponse.json()).ocs.data
	const contextResponse = await ocsRequest(page.request, user, {
		method: 'POST',
		url: '/ocs/v2.php/apps/tables/api/2/contexts?format=json',
		data: {
			name,
			iconName: 'star',
			menuItems: [{ label: pageTitle, targetType: 'view', targetId: view.id }],
		},
	})
	return (await contextResponse.json()).ocs.data
}

test.describe('Applications', () => {
	test('An application opened on its own shows its menu and its first page', async ({ userPage: { page, user } }) => {
		const context = await createApplicationWithGridPage(page, user, 'standalone application', 'Front page')

		await page.goto(`/index.php/apps/tables/app/${context.id}`)

		await expect(page.locator('[data-cy="application-nav-header"]')).toContainText('standalone application')
		await expect(page.locator('[data-cy="application-nav-item"]').filter({ hasText: 'Front page' })).toBeVisible()
		await expect(page).toHaveURL(/\/menu\/front_page$/)
		await expect(page.locator('[data-cy="gridViewHeading"]')).toContainText('Front page')
		await expect(page.locator('[data-cy="navigationCreateTableIcon"]')).toHaveCount(0)
		// Nothing focused the content area before the person touched the page, so no focus ring is drawn around it
		expect(await page.evaluate(() => document.activeElement?.id ?? '')).not.toBe('app-content-vue')
	})

	test('A page with widgets added on the overview gets a menu entry and opens', async ({ userPage: { page } }) => {
		await page.goto('/index.php/apps/tables')
		await createContext(page, 'overview application')
		await loadContext(page, 'overview application')

		await page.locator('[data-cy="context-add-page"]').getByRole('button').first().click()
		await page.getByRole('menuitem', { name: 'Page with widgets' }).click()
		const dialog = page.locator('[data-cy="gridViewSettingsModal"]')
		await dialog.getByRole('textbox', { name: 'Title', exact: true }).fill('Intake home')
		await dialog.locator('[data-cy="gridViewSubmit"]').click()

		await expect(page).toHaveURL(/\/menu\/intake_home$/)
		await expect(page.locator('[data-cy="context-breadcrumb"]')).toContainText('Intake home')
		await expect(page.locator('[data-cy="gridViewHeading"]')).toContainText('Intake home')

		await page.locator('[data-cy="context-menu-overview"]').click()
		await expect(page.locator('[data-cy="page-card"]').filter({ hasText: 'Intake home' })).toContainText('Page with widgets')
	})

	test('A menu entry is reordered with its buttons, sits under another one and keeps its place after a reload', async ({ userPage: { page } }) => {
		await page.goto('/index.php/apps/tables')
		await createContext(page, 'nested menu application')
		await loadContext(page, 'nested menu application')

		await page.locator('[data-cy="context-menu-edit"]').click()
		for (const [label, url] of [['Handbook', 'https://docs.nextcloud.com'], ['Forum', 'https://help.nextcloud.com']]) {
			await page.locator('[data-cy="menuItemAdd"]').click()
			const row = page.locator('[data-cy="menuItemRow"]').last()
			await row.getByRole('textbox', { name: 'Label' }).fill(label)
			await row.getByRole('textbox', { name: 'Link' }).fill(url)
		}

		// Without a pointer: move Forum to the top, then put Handbook under it
		await page.locator('[data-cy="menuItemRow"]').nth(1).locator('[data-cy="menuItemMoveUp"]').click()
		await expect(page.locator('[data-cy="menuItemRow"]').first().getByRole('textbox', { name: 'Label' })).toHaveValue('Forum')
		await page.locator('[data-cy="menuItemRow"]').nth(1).locator('[data-cy="menuItemIndent"]').click()
		await expect(page.locator('[data-cy="menuItemRow"]').nth(1).locator('[data-cy="menuItemOutdent"]')).toBeVisible()

		const saveResponse = page.waitForResponse(response => response.url().includes('/apps/tables/api/2/contexts/') && response.request().method() === 'PUT')
		await page.locator('[data-cy="context-menu-save"]').click()
		expect((await saveResponse).status()).toBe(200)

		await page.reload()
		const pages = page.locator('[data-cy="page-card"]')
		await expect(pages).toHaveCount(2)
		await expect(pages.nth(0)).toContainText('Forum')
		await expect(pages.nth(1)).toContainText('1.1')
		await expect(pages.nth(1)).toContainText('Handbook')
	})

	test('A scheme imported through the dialog brings its menu and the page it opens', async ({ userPage: { page, user } }) => {
		const source = await createApplicationWithGridPage(page, user, 'exported application', 'Exported page')
		const exportResponse = await ocsRequest(page.request, user, {
			method: 'GET',
			url: `/ocs/v2.php/apps/tables/api/2/contexts/${source.id}/scheme/export?format=json`,
		})
		const scheme = (await exportResponse.json()).ocs.data
		// A grid view uuid this instance does not know, as for a scheme exported on another instance
		const fileContent = JSON.stringify(scheme).split(scheme.gridViews[0].uuid).join(randomUUID())
		const targetResponse = await ocsRequest(page.request, user, {
			method: 'POST',
			url: '/ocs/v2.php/apps/tables/api/2/contexts?format=json',
			data: { name: 'importing application', iconName: 'star' },
		})
		const target = (await targetResponse.json()).ocs.data

		await page.goto('/index.php/apps/tables')
		await ensureNavigationOpen(page)
		const contextItem = page.locator('[data-cy="navigationContextItem"]').filter({ hasText: 'importing application' }).first()
		await contextItem.hover()
		await contextItem.getByRole('button', { name: /Actions|Open menu/i }).first().click({ force: true })
		await page.locator('[data-cy="navigationContextExportSchemeBtn"]').filter({ hasText: 'Import scheme' }).click({ force: true })

		const dialog = page.getByRole('dialog', { name: /application scheme/ })
		await dialog.locator('input[type=file]').setInputFiles({
			name: 'exported-application.json',
			mimeType: 'application/json',
			buffer: Buffer.from(fileContent),
		})
		await dialog.getByRole('button', { name: 'Preview' }).click()
		const importResponse = page.waitForResponse(r => r.url().includes('/scheme/import') && r.request().method() === 'POST')
		await dialog.getByRole('button', { name: 'Import' }).click()
		const response = await importResponse

		expect(response.status()).toBe(200)
		expect(response.request().postDataJSON().menuItems).toHaveLength(1)
		await expect(dialog).toContainText('Import completed successfully.')
		const importedResponse = await ocsRequest(page.request, user, {
			method: 'GET',
			url: `/ocs/v2.php/apps/tables/api/2/contexts/${target.id}?format=json`,
		})
		const imported = (await importedResponse.json()).ocs.data
		expect(imported.menuItems.map((item: { label: string }) => item.label)).toEqual(['Exported page'])
	})
})
