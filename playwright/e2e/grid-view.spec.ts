/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { type Page } from '@playwright/test'
import { test, expect } from '../support/fixtures'
import { createContext, loadContext } from '../support/commands'

async function pickOption(page: Page, inputSelector: string, optionText: string) {
	await page.locator(inputSelector).first().click()
	// the select splits the option text for highlighting, so match on the list item, not the accessible name
	const option = page.locator('.vs__dropdown-menu li').filter({ hasText: optionText }).first()
	await option.waitFor({ state: 'visible' })
	await option.click()
}

/** The application settings live behind one menu button on the overview. */
async function openApplicationSettings(page: Page) {
	await page.getByRole('button', { name: 'Application settings' }).click()
	await page.getByRole('menuitem', { name: 'Edit name, icon and members' }).click()
}

test.describe('Grid views inside an application', () => {
	// the navigation stays open on the application page, so the grid needs a desktop-wide window for its twelve columns
	test.use({ viewport: { width: 1600, height: 1000 } })

	test.describe.configure({ mode: 'serial' })
	// the demo path crosses two dialogs and a reload, more than the default budget of one test
	test.setTimeout(180000)

	test('Create an application, add a menu item, design a grid view and keep it after a reload', async ({ userPage: { page } }) => {
		const contextTitle = 'grid demo application'
		// technical names are unique per owner, so a rerun on the same instance needs a new one
		const technicalName = 'grid_demo_' + Date.now()

		await page.goto('/index.php/apps/tables')
		await createContext(page, contextTitle)
		await loadContext(page, contextTitle)

		// The technical name through the application dialog, an external menu item through the inline menu editor
		await openApplicationSettings(page)
		await expect(page.locator('[data-cy="editContextModal"]').first()).toBeVisible()
		await page.locator('[data-cy="editContextTechnicalName"]').fill(technicalName)
		await page.locator('[data-cy="editContextSubmitBtn"]').click()
		await expect(page.locator('[data-cy="context-address"]')).toContainText('/apps/tables/app/' + technicalName)

		await page.locator('[data-cy="context-menu-edit"]').click()
		await page.locator('[data-cy="menuItemAdd"]').click()
		await page.locator('[data-cy="menuItemLabel"]').first().fill('Documentation')
		await page.locator('[data-cy="menuItemRow"] input[placeholder="https://"]').first().fill('https://docs.nextcloud.com')
		await page.locator('[data-cy="context-menu-save"]').click()
		await expect(page.locator('[data-cy="context-menu-preview"] li').filter({ hasText: 'Documentation' })).toBeVisible()
		await expect(page.locator('[data-cy="page-card"]').filter({ hasText: 'Documentation' })).toBeVisible()

		// A grid view created from the application gets its own menu item
		await page.getByRole('button', { name: 'Add page' }).click()
		await page.getByRole('menuitem', { name: 'Page with widgets' }).click()
		await page.locator('[data-cy="gridViewTitle"]').fill('Intake home')
		await page.locator('[data-cy="gridViewDescription"]').fill('Start here every morning')
		await page.locator('[data-cy="gridViewTechnicalName"]').fill('home')
		await page.locator('[data-cy="gridViewSubmit"]').click()
		await expect(page).toHaveURL(/\/menu\/home$/)
		await expect(page.locator('[data-cy="grid-view-title"]')).toHaveText(/Intake home/)
		await expect(page.locator('[data-cy="context-breadcrumb"]')).toContainText('Intake home')

		// Design it: a header and a description
		await page.locator('[data-cy="grid-view-edit"]').click()
		await page.locator('[data-cy="grid-view-add-widget"]').click()
		await page.locator('[data-cy="widgetField-title"] input').fill('Welcome, intake team')
		await page.locator('[data-cy="addWidgetSubmit"]').click()
		await expect(page.locator('[data-cy="grid-item"]')).toHaveCount(1)

		await page.locator('[data-cy="grid-view-add-widget"]').click()
		await pickOption(page, '[data-cy="addWidgetType"] input', 'Description')
		await page.locator('[data-cy="addWidgetTitle"]').fill('How this works')
		await page.locator('[data-cy="widgetField-text"] textarea').fill('Every new request lands in the welcome table.')
		await page.locator('[data-cy="addWidgetSubmit"]').click()
		await expect(page.locator('[data-cy="grid-item"]')).toHaveCount(2)

		const saveResponse = page.waitForResponse(response => response.url().includes('/apps/tables/view/') && response.request().method() === 'PUT')
		await page.locator('[data-cy="grid-view-save"]').click()
		expect((await saveResponse).status()).toBe(200)
		await expect(page.locator('[data-cy="grid-view-edit"]')).toBeVisible()

		await page.reload()
		await expect(page.locator('[data-cy="grid-item"]')).toHaveCount(2)
		await expect(page.locator('[data-cy="grid-widget"]').filter({ hasText: 'Welcome, intake team' })).toBeVisible()
		await expect(page.locator('[data-cy="grid-widget"]').filter({ hasText: 'How this works' })).toBeVisible()
		const header = page.locator('[data-cy="grid-item"]').first()
		await expect(header).toHaveAttribute('gs-w', '12')

		// Drag the description to the right half, save, reload: the move must survive
		await page.locator('[data-cy="grid-view-edit"]').click()
		const description = page.locator('[data-cy="grid-item"]').nth(1)
		await expect(description).toHaveAttribute('gs-x', '0')
		const handle = description.locator('.grid-widget__header')
		const box = await handle.boundingBox()
		if (!box) {
			throw new Error('description widget has no header to drag')
		}
		await page.mouse.move(box.x + 30, box.y + 15)
		await page.mouse.down()
		for (let step = 1; step <= 15; step++) {
			await page.mouse.move(box.x + 30 + (box.width * step) / 15, box.y + 15, { steps: 2 })
		}
		await page.mouse.up()
		await expect(description).toHaveAttribute('gs-x', '6')
		const moveResponse = page.waitForResponse(response => response.url().includes('/apps/tables/view/') && response.request().method() === 'PUT')
		await page.locator('[data-cy="grid-view-save"]').click()
		expect((await moveResponse).status()).toBe(200)
		await page.reload()
		await expect(page.locator('[data-cy="grid-item"]').nth(1)).toHaveAttribute('gs-x', '6')

		// The same kind of move without a pointer: the widget menu moves it one column back
		await page.locator('[data-cy="grid-view-edit"]').click()
		await page.locator('[data-cy="grid-item"]').nth(1).getByRole('button', { name: 'Widget actions' }).click()
		await page.getByRole('menuitem', { name: 'Move left' }).click()
		await expect(page.locator('[data-cy="grid-item"]').nth(1)).toHaveAttribute('gs-x', '5')
		await page.locator('[data-cy="grid-view-save"]').click()
		await expect(page.locator('[data-cy="grid-view-edit"]')).toBeVisible()

		// Removing a widget and saving must show the result at once, without a reload
		await page.locator('[data-cy="grid-view-edit"]').click()
		const descriptionWidget = page.locator('[data-cy="grid-widget"]').filter({ hasText: 'How this works' })
		await descriptionWidget.locator('.action-item__menutoggle, button[aria-label]').first().click()
		await page.locator('.action-button, [role=menuitem]').filter({ hasText: /remove/i }).first().click()
		await expect(page.locator('[data-cy="grid-item"]')).toHaveCount(1)
		await page.locator('[data-cy="grid-view-save"]').click()
		await expect(page.locator('[data-cy="grid-view-edit"]')).toBeVisible()
		await expect(page.locator('[data-cy="grid-item"]')).toHaveCount(1)
		await expect(page.locator('[data-cy="grid-widget"]').filter({ hasText: 'How this works' })).toHaveCount(0)
	})

	test('An application opened on its own shows its menu and its first page', async ({ userPage: { page } }) => {
		const contextTitle = 'standalone demo application'
		// technical names are scoped per user, but a unique one keeps reruns on a shared instance honest
		const technicalName = 'standalone_demo_' + Date.now()
		await page.goto('/index.php/apps/tables')
		await createContext(page, contextTitle)
		await loadContext(page, contextTitle)
		await openApplicationSettings(page)
		await page.locator('[data-cy="editContextTechnicalName"]').fill(technicalName)
		await page.locator('[data-cy="editContextSubmitBtn"]').click()
		await expect(page.locator('[data-cy="context-address"]')).toContainText('/apps/tables/app/' + technicalName)

		await page.getByRole('button', { name: 'Add page' }).click()
		await page.getByRole('menuitem', { name: 'Page with widgets' }).click()
		await page.locator('[data-cy="gridViewTitle"]').fill('Front page')
		await page.locator('[data-cy="gridViewSubmit"]').click()
		await expect(page).toHaveURL(/\/menu\/front_page$/)

		await page.goto('/index.php/apps/tables/app/' + technicalName)
		await expect(page.locator('[data-cy="application-nav-header"]')).toContainText(contextTitle)
		await expect(page.locator('[data-cy="application-nav-item"]').filter({ hasText: 'Front page' })).toBeVisible()
		await expect(page).toHaveURL(/\/menu\/front_page$/)
		await expect(page.locator('[data-cy="grid-view-title"]')).toHaveText(/Front page/)
		await expect(page.locator('[data-cy="navigationCreateTableIcon"]')).toHaveCount(0)
		// nothing has focused the content area before the person touched the page, so no focus ring is drawn around it
		expect(await page.evaluate(() => document.activeElement?.id ?? '')).not.toBe('app-content-vue')
	})

	test('A menu entry is reordered with its buttons, sits under another one and keeps its place after a reload', async ({ userPage: { page } }) => {
		const contextTitle = 'nested menu application'
		await page.goto('/index.php/apps/tables')
		await createContext(page, contextTitle)
		await loadContext(page, contextTitle)

		await page.locator('[data-cy="context-menu-edit"]').click()
		for (const [label, url] of [['Handbook', 'https://docs.nextcloud.com'], ['Forum', 'https://help.nextcloud.com']]) {
			await page.locator('[data-cy="menuItemAdd"]').click()
			await page.locator('[data-cy="menuItemLabel"]').last().fill(label)
			await page.locator('[data-cy="menuItemRow"]').last().locator('input[placeholder="https://"]').fill(url)
		}

		// without a pointer: move Forum to the top, then put Handbook under it
		await page.locator('[data-cy="menuItemRow"]').nth(1).locator('[data-cy="menuItemMoveUp"]').click()
		await expect(page.locator('[data-cy="menuItemLabel"]').first()).toHaveValue('Forum')
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
