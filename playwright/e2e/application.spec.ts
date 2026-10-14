/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Page } from '@playwright/test'
import { test, expect } from '../support/fixtures'
import { ocsRequest, type TestUser } from '../support/api'

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
})
