/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { type APIRequestContext, type Page } from '@playwright/test'
import { test, expect } from '../support/fixtures'
import { createRandomUser, ocsRequest, type TestUser } from '../support/api'
import { login } from '../support/login'

// The app navigation takes width from the canvas; the grid only arranges at 12 columns
test.use({ viewport: { width: 1600, height: 1000 } })

const API = '/ocs/v2.php/apps/tables/api/2'

async function createTableViaApi(request: APIRequestContext, user: TestUser, title: string): Promise<number> {
	const res = await ocsRequest(request, user, { method: 'POST', url: '/index.php/apps/tables/api/1/tables', data: { title } })
	return (await res.json()).id
}

async function createApplicationViaApi(request: APIRequestContext, user: TestUser, name: string, tableIds: number[]): Promise<number> {
	const res = await ocsRequest(request, user, {
		method: 'POST',
		url: `${API}/contexts?format=json`,
		data: { name, iconName: 'equalizer', nodes: tableIds.map(id => ({ id, type: 0, permissions: 1 })) },
	})
	return (await res.json()).ocs.data.id
}

async function getApplication(request: APIRequestContext, user: TestUser, contextId: number) {
	const res = await ocsRequest(request, user, { method: 'GET', url: `${API}/contexts/${contextId}?format=json` })
	return (await res.json()).ocs.data
}

/**
 * Opens the start page of an application inside Tables; the application itself opens on its overview.
 */
async function openApplication(page: Page, user: TestUser, contextId: number) {
	const application = await getApplication(page.request, user, contextId)
	const startPage = Object.values(application.pages as Record<string, { id: number, page_type: string }>).find(entry => entry.page_type === 'startpage')
	await page.goto(`/index.php/apps/tables/#/application/${contextId}/page/${startPage!.id}`)
	await expect(page.locator('[data-cy="gridPage"]')).toBeVisible({ timeout: 30000 })
}

function startPageContent(application: { pages: Record<string, { page_type: string, content: Record<string, { order: number, type: string, node_rel_id: number | null, grid_y: number }> }> }) {
	const startPage = Object.values(application.pages).find(page => page.page_type === 'startpage')
	return Object.values(startPage?.content ?? {})
}

test.describe('Application pages on the grid', () => {
	test('An existing start page shows its tables in order before any edit', async ({ userPage: { page, user } }) => {
		const first = await createTableViaApi(page.request, user, 'grid first table')
		const second = await createTableViaApi(page.request, user, 'grid second table')
		const contextId = await createApplicationViaApi(page.request, user, 'grid existing app', [first, second])

		await openApplication(page, user, contextId)

		const items = page.locator('[data-cy="gridItem"]')
		await expect(items).toHaveCount(2)
		const firstHeading = page.locator('h1', { hasText: 'grid first table' })
		const secondHeading = page.locator('h1', { hasText: 'grid second table' })
		await expect(firstHeading).toBeVisible()
		await expect(secondHeading).toBeVisible()
		const firstBox = await firstHeading.boundingBox()
		const secondBox = await secondHeading.boundingBox()
		expect(firstBox!.y).toBeLessThan(secondBox!.y)
		// Both full width, one below the other
		const [firstItem, secondItem] = [await items.nth(0).boundingBox(), await items.nth(1).boundingBox()]
		expect(Math.round(firstItem!.width)).toBe(Math.round(secondItem!.width))
	})

	test('A header added to the start page is saved with the order filled in', async ({ userPage: { page, user } }) => {
		const table = await createTableViaApi(page.request, user, 'grid header table')
		const contextId = await createApplicationViaApi(page.request, user, 'grid header app', [table])
		await openApplication(page, user, contextId)

		await page.locator('[data-cy="gridPageEdit"]').click()
		await page.locator('[data-cy="gridPageAddWidget"]').click()
		const dialog = page.locator('[data-cy="addWidgetModal"]')
		await expect(dialog).toBeVisible()
		await dialog.locator('[data-cy="widgetFieldTitle"] input').fill('Welcome to the dashboard')
		await page.locator('[data-cy="addWidgetSubmit"]').click()
		await expect(dialog).toBeHidden()

		const saved = page.waitForResponse(r => r.url().includes('/widgets') && r.request().method() === 'PUT')
		await page.locator('[data-cy="gridPageSave"]').click()
		expect((await saved).ok()).toBe(true)

		await page.reload()
		await expect(page.locator('[data-cy="gridPage"]')).toBeVisible({ timeout: 30000 })
		await expect(page.getByText('Welcome to the dashboard')).toBeVisible()
		await expect(page.locator('h1', { hasText: 'grid header table' })).toBeVisible()

		const content = startPageContent(await getApplication(page.request, user, contextId))
			.sort((a, b) => a.grid_y - b.grid_y)
		expect(content.map(entry => entry.type)).toEqual(['data', 'header'])
		expect(content.map(entry => entry.order)).toEqual([10, 20])
	})

	test('A data widget on a new page adds its table to the application', async ({ userPage: { page, user } }) => {
		const inApp = await createTableViaApi(page.request, user, 'grid table in app')
		const notInApp = await createTableViaApi(page.request, user, 'grid table not in app')
		const contextId = await createApplicationViaApi(page.request, user, 'grid new page app', [inApp])
		await openApplication(page, user, contextId)

		await page.locator('[data-cy="contextPageCreate"]').click()
		const pageForm = page.getByRole('dialog', { name: 'New page' })
		await pageForm.getByRole('textbox', { name: 'Title' }).fill('Overview')
		await pageForm.getByRole('textbox', { name: 'Technical name' }).fill('overview')
		await page.locator('[data-cy="contextPageSubmit"]').click()
		await expect(page).toHaveURL(/\/application\/\d+\/page\/\d+/)
		await expect(page.locator('[data-cy="contextPageLink"]', { hasText: 'Overview' })).toBeVisible()
		await expect(page.locator('[data-cy="gridItem"]')).toHaveCount(0)

		await page.locator('[data-cy="gridPageEdit"]').click()
		await page.locator('[data-cy="gridPageAddWidget"]').click()
		const dialog = page.locator('[data-cy="addWidgetModal"]')
		await dialog.locator('[data-cy="addWidgetType"] input').click()
		await page.locator('.vs__dropdown-menu li').filter({ hasText: 'Table or view' }).click()
		await dialog.locator('[data-cy="addWidgetNode"] input').click()
		await page.locator('.vs__dropdown-menu li').filter({ hasText: 'grid table not in app' }).click()
		await page.locator('[data-cy="addWidgetSubmit"]').click()
		await expect(dialog).toBeHidden()

		const saved = page.waitForResponse(r => r.url().includes('/widgets') && r.request().method() === 'PUT')
		await page.locator('[data-cy="gridPageSave"]').click()
		expect((await saved).ok()).toBe(true)
		await expect(page.locator('h1', { hasText: 'grid table not in app' })).toBeVisible()

		const application = await getApplication(page.request, user, contextId)
		const nodeIds = Object.values(application.nodes as Record<string, { node_id: number, node_type: number }>)
			.filter(node => node.node_type === 0)
			.map(node => node.node_id)
		expect(nodeIds.sort()).toEqual([inApp, notInApp].sort())
		// The start page keeps only its own table
		expect(startPageContent(application)).toHaveLength(1)
	})

	test('A page created without a technical name gets one from its title, and keeps one on rename', async ({ userPage: { page, user } }) => {
		const table = await createTableViaApi(page.request, user, 'grid name table')
		const contextId = await createApplicationViaApi(page.request, user, 'grid name app', [table])
		await openApplication(page, user, contextId)

		await page.locator('[data-cy="contextPageCreate"]').click()
		const pageForm = page.getByRole('dialog', { name: 'New page' })
		await pageForm.getByRole('textbox', { name: 'Title' }).fill('Open cases')
		const created = page.waitForResponse(r => r.url().includes('/pages') && r.request().method() === 'POST')
		await page.locator('[data-cy="contextPageSubmit"]').click()
		const createdPage = (await (await created).json()).ocs.data
		expect(createdPage.technical_name).toBe('open_cases')
		await expect(page).toHaveURL(new RegExp(`/page/${createdPage.id}$`))

		const pages = Object.values((await getApplication(page.request, user, contextId)).pages as Record<string, { page_type: string, technical_name: string }>)
		expect(pages.map(entry => entry.technical_name).sort()).toEqual(['dashboard', 'open_cases'])

		await page.locator('[data-cy="contextPageActions"] button').first().click()
		await page.locator('[data-cy="contextPageRename"]').click()
		const renameForm = page.getByRole('dialog', { name: 'Rename page' })
		const technicalName = renameForm.getByRole('textbox', { name: 'Technical name' })
		await expect(technicalName).toHaveValue('open_cases')
		await technicalName.fill('')
		await expect(page.locator('[data-cy="contextPageSubmit"]')).toBeDisabled()
		await technicalName.fill('dashboard')
		const renamed = page.waitForResponse(r => r.url().includes('/pages/') && r.request().method() === 'PATCH')
		await page.locator('[data-cy="contextPageSubmit"]').click()
		expect((await renamed).status()).toBe(400)
	})

	test('A sharee who cannot manage the application sees its pages read-only', async ({ userPage: { page, user }, browser, baseURL, request }) => {
		const sharee = await createRandomUser(request)
		const table = await createTableViaApi(page.request, user, 'grid shared table')
		const contextId = await createApplicationViaApi(page.request, user, 'grid shared app', [table])
		await ocsRequest(page.request, user, {
			method: 'POST',
			url: `${API}/contexts/${contextId}/pages?format=json`,
			data: { title: 'Shared overview' },
		})
		await ocsRequest(page.request, user, {
			method: 'POST',
			url: '/index.php/apps/tables/api/1/shares',
			data: { nodeId: contextId, nodeType: 'context', receiver: sharee.userId, receiverType: 'user', permissionRead: true, displayMode: 2 },
		})

		const shareeContext = await browser.newContext({ baseURL, viewport: { width: 1600, height: 1000 } })
		const shareePage = await shareeContext.newPage()
		await login(shareePage, sharee)
		await openApplication(shareePage, sharee, contextId)

		await expect(shareePage.locator('h1', { hasText: 'grid shared table' })).toBeVisible()
		await expect(shareePage.locator('[data-cy="contextPageLink"]', { hasText: 'Shared overview' })).toBeVisible()
		await expect(shareePage.locator('[data-cy="gridPageEdit"]')).toHaveCount(0)
		await expect(shareePage.locator('[data-cy="contextPageCreate"]')).toHaveCount(0)

		const forbidden = await shareePage.request.fetch(`${API}/contexts/${contextId}/pages?format=json`, {
			method: 'POST',
			headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json', Authorization: 'Basic ' + Buffer.from(`${sharee.userId}:${sharee.password}`).toString('base64') },
			data: { title: 'Not allowed' },
		})
		expect(forbidden.status()).toBe(403)

		await shareeContext.close()
	})
})
