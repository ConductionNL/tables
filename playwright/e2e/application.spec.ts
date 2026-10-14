/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Page } from '@playwright/test'
import { test, expect } from '../support/fixtures'
import { ocsRequest, type TestUser } from '../support/api'
import { createContext, loadContext } from '../support/commands'

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

	test('The overview lists the tables the application uses and counts its pages', async ({ userPage: { page, user } }) => {
		const tableResponse = await ocsRequest(page.request, user, {
			method: 'POST',
			url: '/ocs/v2.php/apps/tables/api/2/tables?format=json',
			data: { title: 'Requests', template: 'custom' },
		})
		const table = (await tableResponse.json()).ocs.data
		const contextResponse = await ocsRequest(page.request, user, {
			method: 'POST',
			url: '/ocs/v2.php/apps/tables/api/2/contexts?format=json',
			data: {
				name: 'used tables application',
				iconName: 'star',
				menuItems: [
					{ label: 'All requests', targetType: 'table', targetId: table.id },
					{ label: 'Docs', targetType: 'url', url: 'https://docs.nextcloud.com' },
				],
			},
		})
		const context = (await contextResponse.json()).ocs.data

		await page.goto(`/index.php/apps/tables/#/application/${context.id}`)

		await expect(page.locator('[data-cy="context-stats"]')).toContainText('2 pages')
		await expect(page.locator('[data-cy="context-stats"]')).toContainText('1 table')
		const row = page.locator('[data-cy="context-table-row"]').filter({ hasText: 'Requests' })
		await expect(row).toContainText('All requests')
	})

	test('An application with a technical name is opened at its readable address', async ({ userPage: { page } }) => {
		// Technical names are unique per owner; a fresh one keeps reruns on the same instance apart
		const technicalName = 'intake_' + Date.now()
		await page.goto('/index.php/apps/tables')
		await createContext(page, 'named application')
		await loadContext(page, 'named application')

		await page.getByRole('button', { name: 'Application settings' }).click()
		await page.getByRole('menuitem', { name: 'Edit name, icon and members' }).click()
		await page.locator('[data-cy="editContextTechnicalName"]').fill(technicalName)
		await page.locator('[data-cy="editContextSubmitBtn"]').click()
		await expect(page.locator('[data-cy="context-address"]')).toContainText('/apps/tables/app/' + technicalName)

		await page.goto('/index.php/apps/tables/app/' + technicalName)
		await expect(page.locator('[data-cy="application-nav-header"]')).toContainText('named application')
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
			await page.locator('[data-cy="menuItemLabel"] input').last().fill(label)
			await page.locator('[data-cy="menuItemLink"] input').last().fill(url)
		}

		// Without a pointer: move Forum to the top, then put Handbook under it
		await page.locator('[data-cy="menuItemRow"]').nth(1).locator('[data-cy="menuItemMoveUp"]').click()
		await expect(page.locator('[data-cy="menuItemLabel"] input').first()).toHaveValue('Forum')
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
})
