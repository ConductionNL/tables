<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="overview">
		<header class="overview__header" data-cy="context-header">
			<div class="overview__identity">
				<NcIconSvgWrapper :svg="icon" :size="40" class="overview__icon" />
				<div class="overview__identity-text">
					<h1 class="overview__title" data-cy="context-title">
						{{ context.name }}
					</h1>
					<p v-if="context.description" class="overview__description">
						{{ context.description }}
					</p>
					<div class="overview__address-row">
						<a :href="applicationUrl"
							target="_blank"
							rel="noopener"
							class="overview__address"
							data-cy="context-address">{{ applicationUrl }}</a>
						<NcButton variant="tertiary"
							:aria-label="t('tables', 'Copy the address')"
							:title="t('tables', 'Copy the address')"
							data-cy="context-copy-address"
							@click="copyAddress">
							<template #icon>
								<ContentCopy :size="18" />
							</template>
						</NcButton>
					</div>
				</div>
			</div>
			<div class="overview__header-actions">
				<NcButton variant="primary"
					:href="applicationUrl"
					target="_blank"
					rel="noopener"
					data-cy="context-open-application">
					<template #icon>
						<OpenInNew :size="20" />
					</template>
					{{ t('tables', 'Open application') }}
				</NcButton>
				<NcActions v-if="canManage" :aria-label="t('tables', 'Application settings')" data-cy="context-actions">
					<NcActionButton data-cy="context-edit-application" @click="emitEvent('tables:context:edit', context.id)">
						<template #icon>
							<PencilOutline :size="20" />
						</template>
						{{ t('tables', 'Edit name, icon and members') }}
					</NcActionButton>
					<NcActionButton data-cy="context-import-scheme" @click="emitEvent('tables:context:import-scheme', context.id)">
						<template #icon>
							<TrayArrowDown :size="20" />
						</template>
						{{ t('tables', 'Import a scheme') }}
					</NcActionButton>
					<NcActionButton data-cy="context-transfer" @click="emitEvent('tables:context:transfer', context)">
						<template #icon>
							<SwapHorizontal :size="20" />
						</template>
						{{ t('tables', 'Transfer ownership') }}
					</NcActionButton>
					<NcActionSeparator />
					<NcActionButton class="action--error" data-cy="context-delete" @click="emitEvent('tables:context:delete', context)">
						<template #icon>
							<DeleteOutline :size="20" />
						</template>
						{{ t('tables', 'Delete application') }}
					</NcActionButton>
				</NcActions>
			</div>
		</header>

		<section class="overview__section" data-cy="context-pages">
			<div class="overview__section-header">
				<h2>{{ t('tables', 'Pages') }}</h2>
				<div class="overview__section-actions">
					<NcActions v-if="canManage" :menu-name="t('tables', 'Add page')" data-cy="context-add-page">
						<template #icon>
							<Plus :size="20" />
						</template>
						<NcActionButton data-cy="context-add-grid-page" @click="pageFormOpen = true">
							<template #icon>
								<ViewDashboardOutline :size="20" />
							</template>
							{{ t('tables', 'Page with widgets') }}
						</NcActionButton>
						<NcActionButton data-cy="context-add-table-page" @click="editMenu('table')">
							<template #icon>
								<TableIcon :size="20" />
							</template>
							{{ t('tables', 'Table or view') }}
						</NcActionButton>
						<NcActionButton data-cy="context-add-link" @click="editMenu('url')">
							<template #icon>
								<LinkVariant :size="20" />
							</template>
							{{ t('tables', 'Link') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>
			<div class="overview__table-wrap">
				<table class="overview__table" data-cy="context-pages-table">
					<thead>
						<tr>
							<th scope="col" class="overview__col-position">
								<span aria-hidden="true">#</span>
								<span class="hidden-visually">{{ t('tables', 'Position') }}</span>
							</th>
							<th scope="col">
								{{ t('tables', 'Page') }}
							</th>
							<th scope="col">
								{{ t('tables', 'Type') }}
							</th>
							<th scope="col">
								{{ t('tables', 'Shows') }}
							</th>
							<th scope="col" class="overview__col-actions">
								<span class="hidden-visually">{{ t('tables', 'Actions') }}</span>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(row, index) in pageRows"
							:key="row.page.id"
							class="page-row"
							data-cy="page-card">
							<td class="overview__col-position">
								{{ index + 1 }}
							</td>
							<td>
								<div class="page-row__name">
									<span v-if="row.page.emoji" class="page-row__icon" aria-hidden="true">{{ row.page.emoji }}</span>
									<ViewDashboardOutline v-else :size="20" class="page-row__icon" />
									<router-link :to="row.route" class="page-row__link" data-cy="page-card-open">
										{{ row.title }}
									</router-link>
								</div>
							</td>
							<td>{{ row.typeLabel }}</td>
							<td class="page-row__shows">
								{{ row.description }}
							</td>
							<td class="overview__col-actions">
								<NcButton v-if="canManage"
									variant="tertiary"
									:href="routeHref(row.route + '?edit=1')"
									data-cy="page-card-design">
									<template #icon>
										<PencilOutline :size="20" />
									</template>
									{{ t('tables', 'Design') }}
								</NcButton>
								<NcButton v-else variant="tertiary" :href="routeHref(row.route)">
									{{ t('tables', 'Open') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>

		<section v-if="canManage" class="overview__section" data-cy="context-menu-editor">
			<div class="overview__section-header">
				<h2>{{ t('tables', 'Menu') }}</h2>
				<div class="overview__section-actions">
					<NcButton v-if="menuDraft !== null" variant="tertiary" data-cy="context-menu-cancel" @click="menuDraft = null">
						{{ t('tables', 'Cancel') }}
					</NcButton>
					<NcButton v-if="menuDraft !== null"
						variant="primary"
						:disabled="menuSaving"
						data-cy="context-menu-save"
						@click="saveMenu">
						<template #icon>
							<ContentSaveOutline :size="20" />
						</template>
						{{ t('tables', 'Save menu') }}
					</NcButton>
					<NcButton v-else variant="secondary" data-cy="context-menu-edit" @click="editMenu()">
						<template #icon>
							<PencilOutline :size="20" />
						</template>
						{{ t('tables', 'Edit menu') }}
					</NcButton>
				</div>
			</div>
			<p class="overview__section-help">
				{{ t('tables', 'The menu is what people see on the left when they open the application. Order it the way they work.') }}
			</p>
			<MenuItemsEditor v-if="menuDraft !== null"
				ref="menuItemsEditor"
				v-model:items="menuDraft"
				:pages="pages" />
			<ol v-else-if="menuRows.length > 0" class="overview__menu-preview" data-cy="context-menu-preview">
				<li v-for="row in menuRows"
					:key="row.item.id"
					class="overview__menu-preview-item"
					:class="{ 'overview__menu-preview-item--child': row.level === 1 }"
					data-cy="context-menu-preview-item">
					<span class="overview__menu-preview-position">{{ row.position }}</span>
					<MenuItemIcon :item="row.item" :size="18" />
					<span>{{ row.item.label }}</span>
					<span class="overview__menu-preview-target">{{ row.description }}</span>
				</li>
			</ol>
			<p v-else class="overview__hint">
				{{ t('tables', 'The menu is empty. Add a page, or edit the menu to link pages, tables and external links.') }}
			</p>
		</section>

		<ContextPageForm :show-modal="pageFormOpen"
			:page="null"
			@close="pageFormOpen = false"
			@submit="createPage" />
	</div>
</template>

<script>
import { NcActionButton, NcActionSeparator, NcActions, NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import { emit } from '@nextcloud/event-bus'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { mapActions, mapState } from 'pinia'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import TableIcon from 'vue-material-design-icons/Table.vue'
import TrayArrowDown from 'vue-material-design-icons/TrayArrowDown.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import ContextPageForm from '../../modals/ContextPageForm.vue'
import MenuItemIcon from '../../../shared/components/MenuItemIcon.vue'
import MenuItemsEditor from '../../../shared/components/ncContextResource/MenuItemsEditor.vue'
import { areMenuItemsValid, menuTree, pageTitle, toEditableMenuItems, toMenuItemPayload } from '../../../shared/utils/menuItems.js'
import { useTablesStore } from '../../../store/store.js'
import { PAGE_TYPE_START } from '../../grid/gridLayout.js'

/**
 * Where an application is managed inside Tables: its name and address, its pages and its menu.
 */
export default {
	name: 'ApplicationOverview',
	components: {
		NcActionButton,
		NcActionSeparator,
		NcActions,
		NcButton,
		NcIconSvgWrapper,
		ContentCopy,
		ContentSaveOutline,
		DeleteOutline,
		LinkVariant,
		OpenInNew,
		PencilOutline,
		Plus,
		SwapHorizontal,
		TableIcon,
		TrayArrowDown,
		ViewDashboardOutline,
		ContextPageForm,
		MenuItemIcon,
		MenuItemsEditor,
	},
	props: {
		context: {
			type: Object,
			required: true,
		},
		icon: {
			type: String,
			default: '',
		},
		canManage: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			menuDraft: null,
			menuSaving: false,
			pageFormOpen: false,
			// The widgets of every page by page id, as the page API returns them
			widgetsByPage: {},
		}
	},
	computed: {
		...mapState(useTablesStore, ['tables', 'views']),
		applicationUrl() {
			return window.location.origin + generateUrl('/apps/tables/app/' + this.context.id)
		},
		menuItems() {
			return [...(this.context.menuItems ?? [])].sort((a, b) => a.order - b.order)
		},
		/**
		 * The pages of the application, the start page first, then the grid pages in the order they were added.
		 *
		 * @return {Array<object>}
		 */
		pages() {
			const isStart = page => page.page_type === PAGE_TYPE_START ? 0 : 1
			return Object.values(this.context.pages ?? {}).sort((a, b) => isStart(a) - isStart(b) || a.id - b.id)
		},
		/**
		 * The pages, each with what it shows: its widgets, and on the start page its tables and views.
		 *
		 * @return {Array<object>}
		 */
		pageRows() {
			return this.pages.map(page => {
				const isStartPage = page.page_type === PAGE_TYPE_START
				const widgetCount = this.widgetsByPage[page.id]?.length
				const shows = []
				if (widgetCount !== undefined) {
					shows.push(n('tables', '%n widget', '%n widgets', widgetCount))
				}
				if (isStartPage) {
					shows.push(n('tables', '%n table or view', '%n tables and views', Object.keys(page.content ?? {}).length))
				}
				return {
					page,
					title: pageTitle(page),
					route: this.pageRoute(page),
					typeLabel: isStartPage ? t('tables', 'Start page') : t('tables', 'Page with widgets'),
					description: shows.join(', '),
				}
			})
		},
		/**
		 * The menu items in menu order, each with what it opens, for the menu preview.
		 *
		 * @return {Array<object>}
		 */
		menuRows() {
			const ordered = []
			menuTree(this.menuItems).forEach((branch, rootIndex) => {
				ordered.push({ item: branch.item, position: String(rootIndex + 1), level: 0 })
				branch.children.forEach((child, childIndex) => ordered.push({ item: child, position: (rootIndex + 1) + '.' + (childIndex + 1), level: 1 }))
			})
			return ordered.map(row => ({ ...row, description: this.menuItemDescription(row.item) }))
		},
	},
	watch: {
		'context.pages': {
			handler() {
				this.loadWidgets()
			},
			immediate: true,
		},
	},
	methods: {
		...mapActions(useTablesStore, ['createContextPage', 'loadContextPage', 'updateContextMenuItems']),
		/**
		 * Loads the widgets of every page, for what each page shows.
		 */
		async loadWidgets() {
			const contextId = this.context.id
			const entries = await Promise.all(this.pages.map(async page => {
				try {
					return [page.id, (await this.loadContextPage({ contextId, pageId: page.id })).widgets ?? []]
				} catch {
					// The page then shows without a widget count
					return null
				}
			}))
			if (contextId === this.context.id) {
				this.widgetsByPage = Object.fromEntries(entries.filter(entry => entry !== null))
			}
		},
		emitEvent(name, payload) {
			emit(name, payload)
		},
		/**
		 * @param {object} page a page of the application
		 * @return {string} its route inside Tables; the start page is the application itself
		 */
		pageRoute(page) {
			return page.page_type === PAGE_TYPE_START
				? '/application/' + this.context.id
				: '/application/' + this.context.id + '/page/' + page.id
		},
		/**
		 * The address of a route inside Tables, so a button can be a real link: it opens in a new tab and
		 * can be copied. The router keeps its routes in the hash, so following it stays within the app.
		 *
		 * @param {string} route the route inside Tables
		 * @return {string}
		 */
		routeHref(route) {
			return generateUrl('/apps/tables/') + '#' + route
		},
		/**
		 * @param {object} item a menu item
		 * @return {string} what the item opens, for the menu preview
		 */
		menuItemDescription(item) {
			if (item.targetType === 'url') {
				return item.url
			}
			if (item.broken) {
				return t('tables', 'The page, view or table this entry opened no longer exists')
			}
			if (item.targetType === 'page') {
				const page = this.pages.find(page => page.id === item.targetId)
				return page ? pageTitle(page) : ''
			}
			if (item.targetType === 'table') {
				const table = this.tables.find(table => table.id === item.targetId)
				return table ? n('tables', '%n row', '%n rows', table.rowsCount ?? 0) : t('tables', 'Not accessible to you')
			}
			const view = this.views.find(view => view.id === item.targetId)
			const table = view ? this.tables.find(table => table.id === view.tableId) : null
			if (!view) {
				return t('tables', 'Not accessible to you')
			}
			return table ? t('tables', 'View of {table}', { table: table.title }, undefined, { escape: false }) : view.title
		},
		async copyAddress() {
			try {
				await navigator.clipboard.writeText(this.applicationUrl)
				showSuccess(t('tables', 'Address copied'))
			} catch (e) {
				showError(t('tables', 'The address could not be copied. Select it and copy it yourself.'))
			}
		},
		/**
		 * Opens the menu editor, with a fresh item of the given kind appended when asked for.
		 *
		 * @param {string|null} appendKind 'table' or 'url' to start a new entry of that kind
		 */
		editMenu(appendKind = null) {
			const items = this.menuDraft ?? toEditableMenuItems(this.menuItems)
			if (appendKind !== null) {
				items.push({ key: 'new-' + Date.now(), label: '', icon: null, targetType: appendKind, targetId: null, url: '', technicalName: null, parentKey: null })
			}
			this.menuDraft = items
			this.$nextTick(() => this.$el.querySelector('[data-cy="context-menu-editor"]')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
		},
		async saveMenu() {
			// Show every error now, and never send a menu the server would refuse
			this.$refs.menuItemsEditor?.touchAll()
			if (this.menuDraft === null || !areMenuItemsValid(this.menuDraft)) {
				return
			}
			this.menuSaving = true
			const context = await this.updateContextMenuItems({ id: this.context.id, menuItems: this.menuDraft.map(item => toMenuItemPayload(item, this.menuDraft)) })
			this.menuSaving = false
			if (context) {
				this.menuDraft = null
				showSuccess(t('tables', 'Menu saved'))
			}
		},
		/**
		 * Creates a page with widgets, gives it a menu item right away and opens it. A menu being
		 * edited gets the new item too, so saving it later keeps the item.
		 *
		 * @param {{title: string, emoji: string, description: string, technicalName?: string}} data what the page form collected
		 */
		async createPage(data) {
			const page = await this.createContextPage({ contextId: this.context.id, data })
			if (!page) {
				return
			}
			this.pageFormOpen = false
			const items = toEditableMenuItems(this.menuItems)
			items.push({ key: 'new-' + page.id, label: page.title, icon: null, targetType: 'page', targetId: page.id, url: '', technicalName: page.technicalName ?? null, parentKey: null })
			const context = await this.updateContextMenuItems({ id: this.context.id, menuItems: items.map(item => toMenuItemPayload(item, items)) })
			const added = context?.menuItems?.find(item => item.targetType === 'page' && item.targetId === page.id)
			if (!added) {
				// The page exists already; it only misses its entry in the menu
				showError(t('tables', 'The page "{title}" was created, but could not be added to the menu.', { title: page.title }, undefined, { escape: false }))
			} else if (this.menuDraft !== null) {
				this.menuDraft = [...this.menuDraft, ...toEditableMenuItems([added])]
			}
			await this.$router.push({ path: this.pageRoute(page), query: { edit: '1' } }).catch(err => err)
		},
	},
}
</script>

<style scoped lang="scss">
.overview {
	width: var(--app-content-width, 100%);

	// The header stays put while the sections scroll
	&__header {
		position: sticky;
		top: 0;
		z-index: 15;
		display: flex;
		flex-wrap: wrap;
		align-items: flex-start;
		justify-content: space-between;
		gap: calc(3 * var(--default-grid-baseline));
		padding-block: calc(4 * var(--default-grid-baseline)) calc(2 * var(--default-grid-baseline));
		padding-inline: calc(var(--default-clickable-area) + 4 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
		border-bottom: 1px solid var(--color-border);
		background-color: var(--color-main-background);
	}

	&__identity {
		display: flex;
		flex: 1 1 320px;
		align-items: flex-start;
		gap: calc(3 * var(--default-grid-baseline));
		min-width: 0;
	}

	&__icon {
		flex-shrink: 0;
		margin-top: var(--default-grid-baseline);
	}

	&__identity-text {
		min-width: 0;
	}

	&__title {
		margin: 0;
		font-size: 1.5em;
		font-weight: bold;
		line-height: 1.3;
		overflow-wrap: anywhere;
	}

	&__description {
		max-width: 70ch;
		margin: var(--default-grid-baseline) 0 0;
	}

	&__address-row {
		display: flex;
		align-items: center;
		gap: var(--default-grid-baseline);
		min-width: 0;
		margin-top: var(--default-grid-baseline);
	}

	&__address {
		overflow: hidden;
		min-width: 0;
		color: var(--color-text-maxcontrast);
		font-family: monospace;
		text-overflow: ellipsis;
		white-space: nowrap;

		&:hover,
		&:focus-visible {
			text-decoration: underline;
		}
	}

	&__header-actions {
		display: flex;
		flex-shrink: 0;
		align-items: center;
		gap: calc(2 * var(--default-grid-baseline));
	}

	&__section {
		max-width: 1100px;
		padding-block: calc(5 * var(--default-grid-baseline)) calc(2 * var(--default-grid-baseline));
		padding-inline: calc(var(--default-clickable-area) + 4 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
	}

	&__section-header {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: calc(2 * var(--default-grid-baseline));

		h2 {
			margin: 0;
		}
	}

	&__section-actions {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: calc(2 * var(--default-grid-baseline));
	}

	&__section-help {
		max-width: 70ch;
		margin: var(--default-grid-baseline) 0 calc(3 * var(--default-grid-baseline));
		color: var(--color-text-maxcontrast);
	}

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__table-wrap {
		overflow-x: auto;
		margin-top: calc(2 * var(--default-grid-baseline));
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
	}

	&__table {
		width: 100%;
		border-collapse: collapse;

		th,
		td {
			padding: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
			text-align: start;
			vertical-align: middle;
		}

		thead th {
			background-color: var(--color-background-dark);
			color: var(--color-text-maxcontrast);
			font-weight: 500;
			white-space: nowrap;
		}

		tbody tr {
			border-top: 1px solid var(--color-border);

			&:hover {
				background-color: var(--color-background-hover);
			}
		}
	}

	&__col-position {
		width: 1%;
		color: var(--color-text-maxcontrast);
		font-variant-numeric: tabular-nums;
		text-align: end;
	}

	&__col-actions {
		width: 1%;
		text-align: end;
		white-space: nowrap;
	}

	&__menu-preview {
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__menu-preview-item {
		display: flex;
		align-items: center;
		gap: calc(2 * var(--default-grid-baseline));
		padding-block: var(--default-grid-baseline);

		&--child {
			margin-inline-start: calc(6 * var(--default-grid-baseline));
		}
	}

	&__menu-preview-position {
		min-width: 3ch;
		color: var(--color-text-maxcontrast);
		font-variant-numeric: tabular-nums;
	}

	&__menu-preview-target {
		color: var(--color-text-maxcontrast);
	}
}

.page-row {
	&__name {
		display: flex;
		align-items: center;
		gap: calc(2 * var(--default-grid-baseline));
		min-width: 180px;
	}

	&__icon {
		flex-shrink: 0;
		color: var(--color-primary-element);
	}

	&__link {
		color: inherit;
		font-weight: bold;

		&:hover,
		&:focus-visible {
			text-decoration: underline;
		}
	}

	&__shows {
		overflow: hidden;
		max-width: 36ch;
		color: var(--color-text-maxcontrast);
		text-overflow: ellipsis;
		white-space: nowrap;
	}
}

@media (max-width: 700px) {
	// The header keeps clear of the navigation toggle that floats at the top left
	.overview__section {
		padding-inline-start: calc(4 * var(--default-grid-baseline));
	}

	.overview__header-actions {
		justify-content: flex-start;
		width: 100%;
	}
}

// The same rule Tables uses for its destructive navigation actions
.action--error {
	:deep(.action-button) {
		color: var(--color-text-error);
	}
}
</style>
