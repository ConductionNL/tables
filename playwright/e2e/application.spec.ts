/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { APIRequestContext } from '@playwright/test'
import { test, expect } from '../support/fixtures'
import { ocsRequest, type TestUser } from '../support/api'

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
		await expect(page.locator('[data-cy="contextPageLink"]', { hasText: 'Front page' })).toBeVisible()
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
})
