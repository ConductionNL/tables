<!--
  - SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="row main-context-view">
		<div v-if="loading" class="icon-loading" />

		<div v-else-if="activeContext" :class="{ 'context--standalone': isStandalone }">
			<div v-if="isMenuRoute && !isStandalone" class="context__breadcrumb" data-cy="context-breadcrumb">
				<NcButton variant="tertiary" data-cy="context-menu-overview" @click="openRoute('/application/' + activeContext.id)">
					<template #icon>
						<ChevronLeft :size="20" />
					</template>
					{{ activeContext.name }}
				</NcButton>
				<span class="context__breadcrumb-separator" aria-hidden="true">/</span>
				<span class="context__breadcrumb-current">{{ selectedMenuItem ? selectedMenuItem.label : $route.params.itemName }}</span>
				<NcButton variant="secondary"
					:href="applicationUrl"
					target="_blank"
					rel="noopener"
					class="context__breadcrumb-open"
					data-cy="context-open-application">
					<template #icon>
						<OpenInNew :size="20" />
					</template>
					{{ t('tables', 'Open application') }}
				</NcButton>
			</div>

			<ApplicationOverview v-if="isOverview"
				:context="activeContext"
				:icon="icon"
				:can-manage="canManage" />

			<div v-else-if="!isMenuRoute && !isStandalone" class="content context">
				<div class="row first-row">
					<h1 class="context__title" data-cy="context-title">
						<NcIconSvgWrapper :svg="icon" :size="32" style="display: inline-block;" />&nbsp; {{
							activeContext.name }}
					</h1>
					<NcButton variant="tertiary"
						class="context__overview-link"
						data-cy="context-overview-link"
						@click="openRoute('/application/' + activeContext.id + '/overview')">
						<template #icon>
							<InformationOutline :size="20" />
						</template>
						{{ t('tables', 'Overview') }}
					</NcButton>
				</div>
				<div class="row space-L context__description">
					{{ activeContext.description }}
				</div>
				<ContextPageBar :context="activeContext"
					:active-page-id="activePageId"
					:can-manage="canManage"
					@add-page="openPageForm(null)" />
			</div>

			<div v-if="isMenuRoute && !menuTarget" class="context__menu-target">
				<NcEmptyContent :name="t('tables', 'This menu item points at nothing')"
					:description="t('tables', 'The page, view or table it opened no longer exists, or you have no access to it.')"
					data-cy="context-menu-target-missing" />
			</div>
			<div v-else-if="menuTarget && menuTarget.data" class="context__menu-target" data-cy="context-menu-target">
				<DataWidget :key="menuTarget.data.type + '-' + menuTarget.data.id" :target="menuTarget.data" />
			</div>

			<template v-else-if="activePageId !== null">
				<GridPage v-if="activePage"
					:key="activePage.id"
					:context="activeContext"
					:page="activePage"
					:can-edit="canManage"
					:start-in-edit-mode="$route.query.edit === '1'"
					@saved="page => activePage = page"
					@edit-details="openPageForm"
					@delete="deletePage" />
				<div v-else-if="pageLoading" class="icon-loading" />
				<ErrorMessage v-else :message="t('tables', 'This page could not be found')" />
			</template>

			<GridPage v-else-if="showsStartPage && startPage"
				:key="'start-' + startPage.id"
				:context="activeContext"
				:page="startPage"
				:can-edit="canManage"
				:start-in-edit-mode="$route.query.edit === '1'"
				is-start-page
				@saved="page => startPage = page" />

			<div v-if="showsStartPage" class="resources">
				<div v-for="resource in contextResources" :key="resource.key">
					<div v-if="!resource.isView" class="resource">
						<TableWrapper v-model:view-setting="resourceViewSettings[resource.key]" :table="resource" :columns="columns[resource.key]"
							:rows="rows[resource.key]" @create-column="createColumn(false, resource)"
							@import-scheme="openImportSchemeModal(resource)"
							@import="openImportModal(resource, false)" @download-csv="downloadCSV(resource, false)"
							@download-filtered-csv="rows => downloadFilteredCSV(rows, resource, false)" />
					</div>
					<div v-else-if="resource.isView" class="resource">
						<CustomView v-model:view-setting="resourceViewSettings[resource.key]" :view="resource" :columns="columns[resource.key]"
							:rows="rows[resource.key]" @create-column="createColumn(true, resource)"
							@import="openImportModal(resource, true)" @download-csv="downloadCSV(resource, true)"
							@download-filtered-csv="rows => downloadFilteredCSV(rows, resource, true)" />
					</div>
				</div>
			</div>
		</div>

		<ErrorMessage v-else-if="errorMessage" :message="errorMessage" />

		<ContextPageForm :show-modal="pageFormOpen"
			:page="pageBeingEdited"
			@close="closePageForm"
			@submit="submitPageForm" />
		<MainModals />
	</div>
</template>

<script>
import MainModals from '../modules/modals/Modals.vue'
import { mapState, mapActions, storeToRefs } from 'pinia'
import { NcButton, NcEmptyContent, NcIconSvgWrapper } from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import TableWrapper from '../modules/main/sections/TableWrapper.vue'
import CustomView from '../modules/main/sections/View.vue'
import { emit } from '@nextcloud/event-bus'
import { NODE_TYPE_TABLE, NODE_TYPE_VIEW } from '../shared/constants.ts'
import exportTableMixin from '../shared/components/ncTable/mixins/exportTableMixin.js'
import svgHelper from '../shared/components/ncIconPicker/mixins/svgHelper.js'
import { useTablesStore } from '../store/store.js'
import { useDataStore } from '../store/data.js'
import ErrorMessage from '../modules/main/partials/ErrorMessage.vue'
import ContextPageBar from '../modules/grid/ContextPageBar.vue'
import GridPage from '../modules/grid/GridPage.vue'
import DataWidget from '../modules/grid/widgets/DataWidget.vue'
import ApplicationOverview from '../modules/main/sections/ApplicationOverview.vue'
import ContextPageForm from '../modules/modals/ContextPageForm.vue'
import permissionsMixin from '../shared/components/ncTable/mixins/permissionsMixin.js'
import displayError, { getNotFoundError, getGenericLoadError } from '../shared/utils/displayError.js'
import { showConfirmation, showError } from '@nextcloud/dialogs'
import { menuItemRoute } from '../shared/utils/menuItems.js'
import { PAGE_TYPE_START } from '../modules/grid/gridLayout.js'

/**
 * An application: its title, description and page bar, then the start page or a grid page.
 * A menu item route opens what the item points at. Opened on its own, at /apps/tables/app/{id},
 * the application's menu is the navigation and it starts on its first menu item.
 */
export default {
	components: {
		MainModals,
		NcButton,
		NcEmptyContent,
		NcIconSvgWrapper,
		ChevronLeft,
		InformationOutline,
		OpenInNew,
		DataWidget,
		ApplicationOverview,
		ErrorMessage,
		TableWrapper,
		CustomView,
		ContextPageBar,
		GridPage,
		ContextPageForm,
	},

	mixins: [exportTableMixin, svgHelper, permissionsMixin],
	setup() {
		const store = useDataStore()
		const { getColumns, getRows } = storeToRefs(store)
		return { getColumns, getRows }
	},

	data() {
		return {
			loading: true,
			icon: null,
			resourceViewSettings: {},
			context: null,
			contextResources: [],
			errorMessage: null,
			loadedSignature: null,
			isReloading: false,
			activePage: null,
			startPage: null,
			pageLoading: false,
			pageFormOpen: false,
			pageBeingEdited: null,
			standaloneContextId: loadState('tables', 'contextId', null),
			openedFirstPage: false,
		}
	},

	computed: {
		...mapState(useTablesStore, ['tables', 'contexts', 'activeContextId', 'views', 'activeContext']),
		/**
		 * The grid page in the route, or the one the menu item in the route opens; null otherwise.
		 *
		 * @return {number|null}
		 */
		activePageId() {
			if (this.$route.params.pageId) {
				return parseInt(this.$route.params.pageId)
			}
			return this.menuTarget?.pageId ?? null
		},
		/**
		 * Whether the start page with its tables and views is shown: on the application's own route,
		 * and for a menu item that opens it.
		 *
		 * @return {boolean}
		 */
		showsStartPage() {
			if (this.activePageId !== null || this.isOverview) {
				return false
			}
			return !this.isMenuRoute || this.menuTarget?.startPage === true
		},
		/**
		 * Whether the application was opened on its own, at /apps/tables/app/{id}, with its own menu.
		 *
		 * @return {boolean}
		 */
		isStandalone() {
			return this.standaloneContextId !== null
		},
		applicationUrl() {
			return window.location.origin + generateUrl('/apps/tables/app/' + this.activeContext?.id)
		},
		menuItems() {
			return [...(this.activeContext?.menuItems ?? [])].sort((a, b) => a.order - b.order)
		},
		isMenuRoute() {
			return !!this.$route.params.itemName
		},
		/**
		 * Whether the overview is shown, where the application is managed inside Tables.
		 *
		 * @return {boolean}
		 */
		isOverview() {
			return this.$route.name === 'contextOverview' && !this.isStandalone
		},
		selectedMenuItem() {
			const technicalName = this.$route.params.itemName
			if (!technicalName) {
				return null
			}
			return this.menuItems.find(item => item.technicalName === technicalName) ?? null
		},
		/**
		 * What the selected menu item opens: the start page, a grid page of the application, or a
		 * table or view. Null when the item is unknown, broken or a link.
		 *
		 * @return {{startPage: true}|{pageId: number}|{data: {type: string, id: number}}|null}
		 */
		menuTarget() {
			const item = this.selectedMenuItem
			if (!item || item.broken || item.targetType === 'url') {
				return null
			}
			if (item.targetType === 'page') {
				const page = this.activeContext?.pages?.[item.targetId]
				if (!page) {
					return null
				}
				return page.page_type === PAGE_TYPE_START ? { startPage: true } : { pageId: page.id }
			}
			return { data: { type: item.targetType, id: item.targetId } }
		},
		/**
		 * The menu item an application opened on its own starts on: its first entry that is not a link.
		 *
		 * @return {object|null}
		 */
		firstStandalonePage() {
			if (!this.isStandalone || this.openedFirstPage) {
				return null
			}
			return this.menuItems.find(item => item.targetType !== 'url') ?? null
		},
		canManage() {
			return this.ownsContext(this.activeContext)
		},
		rows() {
			const rows = {}
			if (this.context && this.context.nodes) {
				for (const [, node] of Object.entries(this.context.nodes)) {
					if (parseInt(node.node_type) === NODE_TYPE_TABLE) {
						const rowId = this.getKey(false, node.node_id)
						rows[rowId] = this.getRows(false, node.node_id)
					} else if (parseInt(node.node_type) === NODE_TYPE_VIEW) {
						const rowId = this.getKey(true, node.node_id)
						rows[rowId] = this.getRows(true, node.node_id)
					}
				}
			}
			return rows

		},

		columns() {
			const columns = {}
			if (this.context && this.context.nodes) {
				for (const [, node] of Object.entries(this.context.nodes)) {
					if (parseInt(node.node_type) === NODE_TYPE_TABLE) {
						const columnId = this.getKey(false, node.node_id)
						columns[columnId] = this.getColumns(false, node.node_id)
					} else if (parseInt(node.node_type) === NODE_TYPE_VIEW) {
						const columnId = this.getKey(true, node.node_id)
						columns[columnId] = this.getColumns(true, node.node_id)
					}

				}
			}
			return columns
		},
	},

	watch: {
		menuItems() {
			this.openFirstPageWhenStandalone()
		},
		activeContext: {
			handler() {
				if (this.errorMessage) {
					// Already showing an error, don't redirect
					return
				}
				const signature = this.contextSignature()
				if (signature === this.loadedSignature) {
					return
				}
				this.loadedSignature = signature
				this.reload()
			},
		},
		activePageId(pageId) {
			if (pageId !== null) {
				this.loadActivePage()
			}
		},
		showsStartPage(shown) {
			// The start page resources are only loaded while the start page is shown
			if (shown) {
				this.reload()
			}
		},
		'context.iconName': {
			async handler(value) {
				this.icon = value ? await this.getContextIcon(value) : ''
			},
			immediate: true,
		},
	},

	async mounted() {
		// An application opened on its own shows its menu in the navigation, which must stay open
		if (!this.isStandalone) {
			emit('toggle-navigation', {
				open: false,
			})
		}
		await this.reload()
		this.openFirstPageWhenStandalone()
	},

	methods: {
		...mapActions(useTablesStore, ['loadContext', 'validateExportAccess', 'loadContextTable', 'loadContextView', 'loadContextPage', 'createContextPage', 'updateContextPage', 'deleteContextPage']),
		...mapActions(useDataStore, ['loadColumnsFromBE', 'loadRowsFromBE', 'loadRelationsFromBE']),
		/**
		 * Navigates inside the app. NcButton cannot take a route itself: @nextcloud/vue brings its own
		 * vue-router, whose router key the app's router does not provide, so its "to" renders a plain button.
		 *
		 * @param {string} route the route to open
		 */
		openRoute(route) {
			this.$router.push(route).catch(err => err)
		},
		/**
		 * An application opened on its own starts on its first menu item. Only once: the start
		 * page stays reachable from the navigation afterwards.
		 */
		openFirstPageWhenStandalone() {
			if (this.$route.params.itemName || this.$route.params.pageId || !this.activeContext) {
				return
			}
			const first = this.firstStandalonePage
			if (first) {
				this.openedFirstPage = true
				this.$router.replace(menuItemRoute(first, this.activeContext.id)).catch(err => err)
			}
		},
		contextSignature() {
			const ctx = this.activeContext
			return ctx ? `${ctx.id}:${Object.keys(ctx.nodes || {}).sort().join(',')}` : null
		},
		async reload() {
			if (!this.activeContextId) {
				return
			}

			if (this.isReloading) {
				return
			}
			this.isReloading = true
			this.loading = true
			this.contextResources = []
			this.resourceViewSettings = {}

			try {
				await this.loadContext({ id: this.activeContextId })
				const index = this.contexts.findIndex(c => parseInt(c.id) === parseInt(this.activeContextId))
				this.context = this.contexts[index]

				if (!this.context) {
					this.errorMessage = t('tables', 'This application could not be found')
					return
				}

				this.loadedSignature = this.contextSignature()

				this.icon = await this.getContextIcon(this.activeContext.iconName)

				if (this.activePageId !== null) {
					await this.loadActivePage()
				} else if (this.showsStartPage && this.context && this.context.pages) {
					await this.loadStartPage()
					const pages = Object.values(this.context.pages)
					const startPage = pages.find(p => p.page_type === PAGE_TYPE_START)

					if (startPage && startPage.content) {
						const sortedContent = Object.values(startPage.content).sort((a, b) => a.order - b.order)

						for (const content of sortedContent) {
							const node = this.context.nodes[content.node_rel_id]
							if (!node) continue

							try {
								const nodeType = parseInt(node.node_type)
								if (nodeType === NODE_TYPE_TABLE) {

									await this.loadContextTable({ id: node.node_id })
									const table = this.tables.find(table => table.id === node.node_id)
									if (table) {
										await this.loadColumnsFromBE({
											view: null,
											tableId: table.id,
										})
										await this.loadRowsFromBE({
											viewId: null,
											tableId: table.id,
										})
										await this.loadRelationsFromBE({
											tableId: table.id,
										})
										table.key = (table.id).toString()
										table.isView = false
										this.resourceViewSettings[table.key] = this.createViewSetting(table)
										this.contextResources.push(table)
									}

								} else if (nodeType === NODE_TYPE_VIEW) {
									await this.loadContextView({ id: node.node_id })
									const view = this.views.find(view => view.id === node.node_id)
									if (view) {
										await this.loadColumnsFromBE({
											view,
										})
										await this.loadRowsFromBE({
											viewId: view.id,
											tableId: view.tableId,
										})
										await this.loadRelationsFromBE({
											viewId: view.id,
										})
										view.key = 'view-' + (view.id).toString()
										view.isView = true
										this.resourceViewSettings[view.key] = this.createViewSetting(view)
										this.contextResources.push(view)
									}
								}
							} catch (err) {
								console.error(`Failed to load resource ${node.node_id}:`, err)
								this.errorMessage = t('tables', 'Some resources in this application could not be loaded')
							}
						}
					}
					// TODO: check this
					// Fallback if no startpage or content (though unexpected for valid contexts with nodes)
					// If nodes exist but not in startpage content, they won't be shown.
					// This matches backend logic where nodes are added to startpage.
				}
			} catch (e) {
				if (e.message === 'NOT_FOUND') {
					this.errorMessage = getNotFoundError('application')
				} else {
					this.errorMessage = getGenericLoadError('application')
					displayError(e, this.errorMessage)
				}
			} finally {
				this.loading = false
				this.isReloading = false
			}
		},
		/**
		 * Loads the grid page in the route with its widgets, and the tables and views of the
		 * application so data widgets can show their titles.
		 */
		async loadActivePage() {
			const pageId = this.activePageId
			this.pageLoading = true
			this.activePage = null
			try {
				const page = await this.loadContextPage({ contextId: this.activeContextId, pageId })
				if (pageId === this.activePageId) {
					this.activePage = page
				}
			} catch (e) {
				if (e?.response?.status !== 404) {
					displayError(e, t('tables', 'Could not load the page.'))
				}
			} finally {
				this.pageLoading = false
			}
			await this.loadApplicationNodes()
		},
		/**
		 * Loads the widgets of the start page, shown above its tables and views.
		 */
		async loadStartPage() {
			this.startPage = null
			const startPage = Object.values(this.context?.pages ?? {}).find(page => page.page_type === PAGE_TYPE_START)
			if (!startPage) {
				return
			}
			try {
				this.startPage = await this.loadContextPage({ contextId: this.activeContextId, pageId: startPage.id })
			} catch (e) {
				displayError(e, t('tables', 'Could not load the page.'))
			}
		},
		/**
		 * Puts the tables and views of the application in the store, without their rows.
		 */
		async loadApplicationNodes() {
			for (const node of Object.values(this.activeContext?.nodes ?? {})) {
				try {
					if (parseInt(node.node_type) === NODE_TYPE_TABLE) {
						await this.loadContextTable({ id: node.node_id })
					} else if (parseInt(node.node_type) === NODE_TYPE_VIEW) {
						await this.loadContextView({ id: node.node_id })
					}
				} catch {
					// A table or view that cannot be loaded is shown by id in the widget dialog
				}
			}
		},
		/**
		 * @param {object|null} page the grid page to edit, or null to add one
		 */
		openPageForm(page) {
			this.pageBeingEdited = page
			this.pageFormOpen = true
		},
		closePageForm() {
			this.pageFormOpen = false
			this.pageBeingEdited = null
		},
		/**
		 * Adds a grid page and opens it, or saves the details of the page being edited.
		 *
		 * @param {{title: string, emoji: string, description: string}} data the page fields
		 */
		async submitPageForm(data) {
			const editing = this.pageBeingEdited
			const page = editing
				? await this.updateContextPage({ contextId: this.activeContextId, pageId: editing.id, data })
				: await this.createContextPage({ contextId: this.activeContextId, data })
			if (!page) {
				return
			}
			this.closePageForm()
			if (editing) {
				this.activePage = page
				return
			}
			await this.$router.push({ name: 'contextPage', params: { contextId: this.activeContextId, pageId: page.id } })
		},
		/**
		 * Deletes a grid page after confirmation and goes back to the start page.
		 *
		 * @param {object} page the page to delete
		 */
		async deletePage(page) {
			let confirmed = false
			try {
				confirmed = await showConfirmation({
					name: t('tables', 'Delete page'),
					text: t('tables', 'Delete the page "{title}" and its widgets? The tables and views stay in the application.', { title: page.title }),
					labelConfirm: t('tables', 'Delete page'),
					labelReject: t('tables', 'Cancel'),
					severity: 'warning',
				})
			} catch {
				// Closing the dialog keeps the page
			}
			if (confirmed && await this.deleteContextPage({ contextId: this.activeContextId, pageId: page.id })) {
				await this.$router.push({ name: 'context', params: { contextId: this.activeContextId } })
			}
		},
		createColumn(isView, element) {
			emit('tables:column:create', { isView, element })
		},
		createViewSetting(element) {
			const viewSetting = {}

			if (element?.sort?.length) {
				viewSetting.presetSorting = [...element.sort]
			}

			return viewSetting
		},
		async downloadCSV(element, isView) {
			const access = await this.validateExportAccess({
				id: element.id,
				isView,
			})

			if (!access?.ok) {
				if (access?.reason === 'NO_ACCESS') {
					showError(t('tables', 'Your access was revoked. Reload the page to update your permissions.'))
				}
				return
			}

			const rowId = this.getKey(isView, element.id)
			const colId = this.getKey(isView, element.id)
			this.downloadCsv(this.rows[rowId], this.columns[colId], element.title)
		},
		async downloadFilteredCSV(rows, element, isView) {
			const access = await this.validateExportAccess({
				id: element.id,
				isView,
			})

			if (!access?.ok) {
				if (access?.reason === 'NO_ACCESS') {
					showError(t('tables', 'Your access was revoked. Reload the page to update your permissions.'))
				}
				return
			}

			const colId = this.getKey(isView, element.id)
			this.downloadCsv(rows, this.columns[colId], element.title)
		},
		getKey(isView, id) {
			return isView ? 'view-' + id : id
		},
		openImportModal(element, isView) {
			emit('tables:modal:import', { element, isView })
		},
		openImportSchemeModal(table) {
			emit('tables:modal:table-scheme-import', { table })
		},
	},
}

</script>

<style scoped lang="scss">
.context {
	.row.first-row {
		position: sticky;
		inset-inline-start: 0;
		top: 0;
		z-index: 15;
		display: flex;
		align-items: center;
		background-color: var(--color-main-background);
		width: var(--app-content-width, 100%);
	}

	&__title {
		display: inline-flex;
	}

	&__overview-link {
		margin-inline: auto calc(4 * var(--default-grid-baseline));
	}

	&__description {
		margin: calc(3 * var(--default-grid-baseline, 4px));
		max-width: 790px;
		margin-inline-start: 32px;
	}

	&:deep(.icon-vue) {
		min-width: 32px;
		min-height: 32px;
	}

	&__breadcrumb {
		position: sticky;
		top: 0;
		z-index: 15;
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: calc(2 * var(--default-grid-baseline));
		padding-block: calc(2 * var(--default-grid-baseline));
		padding-inline: calc(var(--default-clickable-area) + 4 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
		border-bottom: 1px solid var(--color-border);
		background-color: var(--color-main-background);
	}

	&__breadcrumb-separator {
		color: var(--color-text-maxcontrast);
	}

	&__breadcrumb-current {
		font-weight: bold;
	}

	&__breadcrumb-open {
		margin-inline-start: auto;
	}

	&__menu-target {
		width: var(--app-content-width, 100%);
		padding-block: calc(2 * var(--default-grid-baseline));
	}
}

.main-context-view {
	width: max-content;
	min-width: var(--app-content-width, 100%);
}

.context--standalone {
	// Leave room for the navigation toggle that floats over the top left corner of the content
	padding-inline-start: var(--default-clickable-area);
}

.resource {
	margin: 40px 0;
	width: max-content;
	min-width: var(--app-content-width, 100%);

	&:deep(.row.first-row) {
		margin-inline-start: 0;
		padding-inline-start: 20px;
	}
}

:deep(h1) {
	font-size: unset;
	font-size: revert;
}
</style>
