<!--
  - SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="row main-context-view">
		<div v-if="loading" class="icon-loading" />

		<div v-else-if="activeContext" :class="{ 'context--standalone': isStandalone }">
			<div v-if="selectedMenuItem && !isStandalone" class="context__breadcrumb" data-cy="context-breadcrumb">
				<NcButton variant="tertiary" data-cy="context-menu-overview" @click="openRoute('/application/' + activeContext.id)">
					<template #icon>
						<ChevronLeft :size="20" />
					</template>
					{{ activeContext.name }}
				</NcButton>
				<span class="context__breadcrumb-separator" aria-hidden="true">/</span>
				<span class="context__breadcrumb-current">{{ selectedMenuItem.label }}</span>
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

			<div v-if="selectedMenuItem" class="context__menu-target" data-cy="context-menu-target">
				<NcLoadingIcon v-if="menuTargetLoading" :size="32" class="context__loading" />
				<NcEmptyContent v-else-if="!menuResource"
					:name="t('tables', 'This menu item points at nothing')"
					:description="t('tables', 'The view or table it opened no longer exists, or you have no access to it.')" />
				<GridView v-else-if="menuResource.isView && menuResource.type === 'grid'"
					:view="currentMenuView"
					:can-edit="canManageElement(menuResource)" />
				<div v-else-if="menuResource.isView" class="resource">
					<CustomView v-model:view-setting="menuViewSetting" :view="menuResource" :columns="getColumns(true, menuResource.id)"
						:rows="getRows(true, menuResource.id)" @create-column="createColumn(true, menuResource)"
						@import="openImportModal(menuResource, true)" @download-csv="downloadCSV(menuResource, true)"
						@download-filtered-csv="rows => downloadFilteredCSV(rows, menuResource, true)" />
				</div>
				<div v-else class="resource">
					<TableWrapper v-model:view-setting="menuViewSetting" :table="menuResource" :columns="getColumns(false, menuResource.id)"
						:rows="getRows(false, menuResource.id)" @create-column="createColumn(false, menuResource)"
						@import-scheme="openImportSchemeModal(menuResource)"
						@import="openImportModal(menuResource, false)" @download-csv="downloadCSV(menuResource, false)"
						@download-filtered-csv="rows => downloadFilteredCSV(rows, menuResource, false)" />
				</div>
			</div>

			<div v-if="!selectedMenuItem && !isStandalone" class="content context">
				<div class="row first-row">
					<h1 class="context__title" data-cy="context-title">
						<NcIconSvgWrapper :svg="icon" :size="32" style="display: inline-block;" />&nbsp; {{
							activeContext.name }}
					</h1>
				</div>
				<div class="row space-L context__description">
					{{ activeContext.description }}
				</div>
			</div>

			<div v-if="!selectedMenuItem" class="resources">
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

		<MainModals />
	</div>
</template>

<script>
import MainModals from '../modules/modals/Modals.vue'
import { mapState, mapActions, storeToRefs } from 'pinia'
import { NcButton, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon } from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import GridView from '../modules/grid/GridView.vue'
import permissionsMixin from '../shared/components/ncTable/mixins/permissionsMixin.js'
import { menuItemRoute } from '../shared/utils/menuItems.js'
import TableWrapper from '../modules/main/sections/TableWrapper.vue'
import CustomView from '../modules/main/sections/View.vue'
import { emit } from '@nextcloud/event-bus'
import { NODE_TYPE_TABLE, NODE_TYPE_VIEW } from '../shared/constants.ts'
import exportTableMixin from '../shared/components/ncTable/mixins/exportTableMixin.js'
import svgHelper from '../shared/components/ncIconPicker/mixins/svgHelper.js'
import { useTablesStore } from '../store/store.js'
import { useDataStore } from '../store/data.js'
import ErrorMessage from '../modules/main/partials/ErrorMessage.vue'
import displayError, { getNotFoundError, getGenericLoadError } from '../shared/utils/displayError.js'
import { showError } from '@nextcloud/dialogs'

export default {
	components: {
		MainModals,
		NcButton,
		NcEmptyContent,
		NcIconSvgWrapper,
		NcLoadingIcon,
		ChevronLeft,
		OpenInNew,
		GridView,
		ErrorMessage,
		TableWrapper,
		CustomView,
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
			menuResource: null,
			menuViewSetting: {},
			menuTargetLoading: false,
			standaloneContextId: loadState('tables', 'contextId', null),
			openedFirstPage: false,
		}
	},

	computed: {
		...mapState(useTablesStore, ['tables', 'contexts', 'activeContextId', 'views', 'activeContext']),
		menuItems() {
			return [...(this.activeContext?.menuItems ?? [])].sort((a, b) => a.order - b.order)
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
		selectedMenuItem() {
			const technicalName = this.$route.params.itemName
			if (!technicalName) {
				return null
			}
			return this.menuItems.find(item => item.technicalName === technicalName) ?? null
		},
		/**
		 * The menu target is loaded once; a view the store has since updated, after a save of its
		 * grid for instance, must show its current state without a reload.
		 *
		 * @return {object|null}
		 */
		currentMenuView() {
			if (!this.menuResource?.isView) {
				return this.menuResource
			}
			const stored = this.views.find(view => view.id === this.menuResource.id)
			return stored ? { ...this.menuResource, ...stored, isView: true } : this.menuResource
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
		selectedMenuItem: {
			immediate: true,
			handler() {
				this.loadMenuTarget()
				this.openFirstPageWhenStandalone()
			},
		},
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
	},

	methods: {
		...mapActions(useTablesStore, ['loadContext', 'validateExportAccess', 'loadContextTable', 'loadContextView']),
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
		 * An application opened on its own starts on its first page, not on the resource overview.
		 * Only once: the overview stays reachable from the menu afterwards.
		 */
		openFirstPageWhenStandalone() {
			if (!this.isStandalone || this.openedFirstPage || this.$route.params.itemName || this.menuItems.length === 0) {
				return
			}
			const first = this.menuItems.find(item => item.targetType !== 'url')
			if (first) {
				this.openedFirstPage = true
				this.$router.replace(menuItemRoute(first, this.activeContext.id)).catch(err => err)
			}
		},
		/**
		 * Loads the view or table the selected menu item opens.
		 */
		async loadMenuTarget() {
			const item = this.selectedMenuItem
			this.menuResource = null
			if (!item || item.targetType === 'url') {
				return
			}
			this.menuTargetLoading = true
			try {
				if (item.targetType === 'view') {
					await this.loadContextView({ id: item.targetId })
					const view = this.views.find(view => view.id === item.targetId)
					if (view) {
						if (view.type !== 'grid') {
							await this.loadColumnsFromBE({ view })
							await this.loadRowsFromBE({ viewId: view.id, tableId: view.tableId })
							await this.loadRelationsFromBE({ viewId: view.id })
						}
						this.menuViewSetting = this.createViewSetting(view)
						this.menuResource = { ...view, isView: true, key: 'view-' + view.id }
					}
				} else {
					await this.loadContextTable({ id: item.targetId })
					const table = this.tables.find(table => table.id === item.targetId)
					if (table) {
						await this.loadColumnsFromBE({ view: null, tableId: table.id })
						await this.loadRowsFromBE({ viewId: null, tableId: table.id })
						await this.loadRelationsFromBE({ tableId: table.id })
						this.menuViewSetting = this.createViewSetting(table)
						this.menuResource = { ...table, isView: false, key: String(table.id) }
					}
				}
			} catch (error) {
				console.error('The menu target could not be loaded', error)
				this.menuResource = null
			} finally {
				this.menuTargetLoading = false
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

				if (this.context && this.context.pages) {
					const pages = Object.values(this.context.pages)
					const startPage = pages.find(p => p.page_type === 'startpage')

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
									// A grid view has no rows; it is a page, reached through the menu
									if (view && view.type !== 'grid') {
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
		background-color: var(--color-main-background);
		width: var(--app-content-width, 100%);
	}

	&__title {
		display: inline-flex;
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

	&__loading {
		margin: calc(4 * var(--default-grid-baseline)) auto;
	}

	&__menu-target {
		width: 100%;
		padding-block: calc(2 * var(--default-grid-baseline));

		.resource {
			overflow-x: auto;
		}
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
}

.main-context-view {
	width: max-content;
	min-width: var(--app-content-width, 100%);
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

.context--standalone {
	width: 100%;

	// Leave room for the navigation toggle that floats over the top left corner of the content
	.context__menu-target {
		padding-inline-start: var(--default-clickable-area);
	}
}

:deep(h1) {
	font-size: unset;
	font-size: revert;
}
</style>
