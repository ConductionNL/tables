/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { APIRequestContext } from '@playwright/test'
import { test, expect } from '../support/fixtures'
import { ocsRequest, type TestUser } from '../support/api'
import { createContext, loadContext } from '../support/commands'

test.use({ viewport: { width: 1600, height: 1000 } })

const API = '/ocs/v2.php/apps/tables/api/2'

/**
 * Creates an application with a table on its start page, a grid page with a header, and a menu
 * that opens the start page, the grid page, the table and an external link, through the API.
 *
 * @param request the request context of the test user
 * @param user the test user
 * @param name name of the application
 */
async function createApplicationWithMenu(request: APIRequestContext, user: TestUser, name: string) {
	const tableResponse = await ocsRequest(request, user, { method: 'POST', url: '/index.php/apps/tables/api/1/tables', data: { title: name + ' cases' } })
	const tableId = (await tableResponse.json()).id
	const contextResponse = await ocsRequest(request, user, {
		method: 'POST',
		url: `${API}/contexts?format=json`,
		data: { name, iconName: 'star', nodes: [{ id: tableId, type: 0, permissions: 1 }] },
	})
	const context = (await contextResponse.json()).ocs.data
	// A new application's dashboard item opens its start page
	const startPageId = context.menuItems.find((item: { technicalName: string }) => item.technicalName === 'dashboard').targetId
	const pageResponse = await ocsRequest(request, user, { method: 'POST', url: `${API}/contexts/${context.id}/pages?format=json`, data: { title: 'Front page' } })
	const frontPageId = (await pageResponse.json()).ocs.data.id
	await ocsRequest(request, user, {
		method: 'PUT',
		url: `${API}/contexts/${context.id}/pages/${frontPageId}/widgets?format=json`,
		data: { widgets: [{ type: 'header', configuration: {}, content: { title: 'Welcome on the front page' }, gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 2 }] },
	})
	await ocsRequest(request, user, {
		method: 'PUT',
		url: `${API}/contexts/${context.id}?format=json`,
		data: {
			menuItems: [
				{ label: 'Dashboard', targetType: 'page', targetId: startPageId, technicalName: 'dashboard' },
				{ label: 'Front page', targetType: 'page', targetId: frontPageId },
				{ label: 'Cases', targetType: 'table', targetId: tableId },
				{ label: 'Handbook', targetType: 'url', url: 'https://docs.nextcloud.com' },
			],
		},
	})
	return { contextId: context.id, frontPageId, tableTitle: name + ' cases' }
}

test.describe('Applications', () => {
	test('An application opened on its own shows its menu and opens what each item points at', async ({ userPage: { page, user } }) => {
		const { contextId, tableTitle } = await createApplicationWithMenu(page.request, user, 'standalone application')

		await page.goto(`/index.php/apps/tables/app/${contextId}`)

		await expect(page.locator('[data-cy="application-nav-header"]')).toContainText('standalone application')
		const items = page.locator('[data-cy="application-nav-item"]')
		await expect(items).toHaveCount(4)
		// The first menu item is the dashboard: the start page with the application's table
		await expect(page).toHaveURL(/\/menu\/dashboard$/)
		await expect(page.locator('h1', { hasText: tableTitle })).toBeVisible({ timeout: 30000 })
		await expect(page.locator('[data-cy="navigationCreateTableIcon"]')).toHaveCount(0)
		await expect(page.locator('[data-cy="application-nav-overview"]')).toHaveCount(0)
		// Nothing focused the content area before the person touched the page, so no focus ring is drawn around it
		expect(await page.evaluate(() => document.activeElement?.id ?? '')).not.toBe('app-content-vue')

		await items.filter({ hasText: 'Front page' }).getByRole('link').click()
		await expect(page).toHaveURL(/\/menu\/front_page$/)
		await expect(page.getByText('Welcome on the front page')).toBeVisible()

		await items.filter({ hasText: 'Cases' }).getByRole('link').click()
		await expect(page).toHaveURL(/\/menu\/cases$/)
		await expect(page.locator('h1', { hasText: tableTitle })).toBeVisible()

		await expect(items.filter({ hasText: 'Handbook' }).getByRole('link')).toHaveAttribute('href', 'https://docs.nextcloud.com')
	})

	test('Inside Tables a menu item opens below a breadcrumb back to the application', async ({ userPage: { page, user } }) => {
		const { contextId } = await createApplicationWithMenu(page.request, user, 'breadcrumb application')

		await page.goto(`/index.php/apps/tables/#/application/${contextId}/menu/front_page`)

		const breadcrumb = page.locator('[data-cy="context-breadcrumb"]')
		await expect(breadcrumb).toContainText('breadcrumb application')
		await expect(breadcrumb).toContainText('Front page')
		await expect(page.getByText('Welcome on the front page')).toBeVisible()
		await expect(page.locator('[data-cy="context-open-application"]')).toHaveAttribute('href', new RegExp(`/apps/tables/app/${contextId}$`))

		await page.locator('[data-cy="context-menu-overview"]').click()
		await expect(page).toHaveURL(new RegExp(`/application/${contextId}$`))
		await expect(page.locator('[data-cy="page-card"]').filter({ hasText: 'Front page' })).toBeVisible()
	})

	test('A menu item whose page is gone shows as broken', async ({ userPage: { page, user } }) => {
		const { contextId, frontPageId } = await createApplicationWithMenu(page.request, user, 'broken item application')
		await ocsRequest(page.request, user, { method: 'DELETE', url: `${API}/contexts/${contextId}/pages/${frontPageId}?format=json` })

		await page.goto(`/index.php/apps/tables/app/${contextId}`)
		await expect(page).toHaveURL(/\/menu\/dashboard$/)
		await page.locator('[data-cy="application-nav-item"]').filter({ hasText: 'Front page' }).getByRole('link').click()

		await expect(page.locator('[data-cy="context-menu-target-missing"]')).toBeVisible()
		await expect(page.locator('[data-cy="application-nav-item"]').filter({ hasText: 'Front page' }).locator('.alert-circle-outline-icon')).toHaveCount(1)
	})

	test('An application whose menu has no dashboard item keeps its start page in the navigation', async ({ userPage: { page, user } }) => {
		const { contextId, tableTitle } = await createApplicationWithMenu(page.request, user, 'no dashboard application')
		await ocsRequest(page.request, user, {
			method: 'PUT',
			url: `${API}/contexts/${contextId}?format=json`,
			data: { menuItems: [{ label: 'Handbook', targetType: 'url', url: 'https://docs.nextcloud.com' }] },
		})

		await page.goto(`/index.php/apps/tables/app/${contextId}`)

		const startPage = page.locator('[data-cy="application-nav-overview"]')
		await expect(startPage).toContainText('Dashboard')
		await expect(page.locator('h1', { hasText: tableTitle })).toBeVisible({ timeout: 30000 })
	})

	test('A page with widgets added on the overview gets a menu entry and opens in edit mode', async ({ userPage: { page } }) => {
		await page.goto('/index.php/apps/tables')
		await createContext(page, 'overview application')
		await loadContext(page, 'overview application')

		await expect(page.locator('[data-cy="page-card"]')).toHaveCount(1)
		await expect(page.locator('[data-cy="page-card"]').first()).toContainText('Dashboard')

		await page.locator('[data-cy="context-add-page"]').getByRole('button').first().click()
		await page.getByRole('menuitem', { name: 'Page with widgets' }).click()
		const form = page.getByRole('dialog', { name: 'New page' })
		await form.getByRole('textbox', { name: 'Title' }).fill('Intake home')
		await page.locator('[data-cy="contextPageSubmit"]').click()

		await expect(page).toHaveURL(/\/application\/\d+\/page\/\d+\?edit=1$/)
		await expect(page.locator('[data-cy="context-breadcrumb"]')).toContainText('Intake home')
		await expect(page.locator('[data-cy="gridPageSave"]')).toBeVisible()

		await page.locator('[data-cy="gridPageCancel"]').click()
		await page.locator('[data-cy="context-menu-overview"]').click()
		const intake = page.locator('[data-cy="page-card"]').filter({ hasText: 'Intake home' })
		await expect(intake).toContainText('Page with widgets')
		await expect(page.locator('[data-cy="context-menu-preview-item"]').filter({ hasText: 'Intake home' })).toBeVisible()
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

		// Without a pointer: move Forum above Handbook, then put Handbook under it
		const rows = page.locator('[data-cy="menuItemRow"]')
		await rows.nth(2).locator('[data-cy="menuItemMoveUp"]').click()
		await expect(rows.nth(1).getByRole('textbox', { name: 'Label' })).toHaveValue('Forum')
		await rows.nth(2).locator('[data-cy="menuItemIndent"]').click()
		await expect(rows.nth(2).locator('[data-cy="menuItemOutdent"]')).toBeVisible()

		const saveResponse = page.waitForResponse(response => response.url().includes('/apps/tables/api/2/contexts/') && response.request().method() === 'PUT')
		await page.locator('[data-cy="context-menu-save"]').click()
		expect((await saveResponse).status()).toBe(200)

		await page.reload()
		const items = page.locator('[data-cy="context-menu-preview-item"]')
		await expect(items).toHaveCount(3)
		await expect(items.nth(0)).toContainText('Dashboard')
		await expect(items.nth(1)).toContainText('Forum')
		await expect(items.nth(2)).toContainText('2.1')
		await expect(items.nth(2)).toContainText('Handbook')
	})

	test('A menu entry can be pointed at a page of the application', async ({ userPage: { page, user } }) => {
		const { contextId } = await createApplicationWithMenu(page.request, user, 'page target application')
		await ocsRequest(page.request, user, { method: 'POST', url: `${API}/contexts/${contextId}/pages?format=json`, data: { title: 'Reports' } })

		await page.goto(`/index.php/apps/tables/#/application/${contextId}`)
		await page.locator('[data-cy="context-menu-edit"]').click()
		await page.locator('[data-cy="menuItemAdd"]').click()
		const row = page.locator('[data-cy="menuItemRow"]').last()
		await row.locator('[data-cy="menuItemTarget"] input').click()
		await page.locator('.vs__dropdown-menu li').filter({ hasText: 'Reports (page)' }).click()
		await expect(row.getByRole('textbox', { name: 'Label' })).toHaveValue('Reports')

		const saveResponse = page.waitForResponse(response => response.url().includes('/apps/tables/api/2/contexts/') && response.request().method() === 'PUT')
		await page.locator('[data-cy="context-menu-save"]').click()
		expect((await saveResponse).status()).toBe(200)
		await expect(page.locator('[data-cy="context-menu-preview-item"]').filter({ hasText: 'Reports' }).last()).toBeVisible()

		await page.goto(`/index.php/apps/tables/app/${contextId}`)
		await page.locator('[data-cy="application-nav-item"]').filter({ hasText: 'Reports' }).getByRole('link').click()
		await expect(page).toHaveURL(/\/menu\/reports$/)
		await expect(page.locator('[data-cy="gridPage"]')).toBeVisible()
	})
})
