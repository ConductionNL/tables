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

			<div v-if="!selectedMenuItem && !isStandalone" class="context__overview">
				<header class="context__header" data-cy="context-header">
					<div class="context__identity">
						<NcIconSvgWrapper :svg="icon" :size="40" class="context__icon" />
						<div class="context__identity-text">
							<h1 class="context__title" data-cy="context-title">
								{{ activeContext.name }}
							</h1>
							<p v-if="activeContext.description" class="context__description">
								{{ activeContext.description }}
							</p>
							<div class="context__address-row">
								<a :href="applicationUrl"
									target="_blank"
									rel="noopener"
									class="context__address"
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
					<div class="context__header-actions">
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
						<NcActions v-if="ownsContext(activeContext)" :aria-label="t('tables', 'Application settings')" data-cy="context-actions">
							<NcActionButton data-cy="context-edit-application" @click="editApplication">
								<template #icon>
									<PencilOutline :size="20" />
								</template>
								{{ t('tables', 'Edit name, icon and members') }}
							</NcActionButton>
							<NcActionButton data-cy="context-import-scheme" @click="importScheme">
								<template #icon>
									<TrayArrowDown :size="20" />
								</template>
								{{ t('tables', 'Import a scheme') }}
							</NcActionButton>
							<NcActionButton data-cy="context-transfer" @click="transferApplication">
								<template #icon>
									<SwapHorizontal :size="20" />
								</template>
								{{ t('tables', 'Transfer ownership') }}
							</NcActionButton>
							<NcActionSeparator />
							<NcActionButton class="action--error" data-cy="context-delete" @click="deleteApplication">
								<template #icon>
									<DeleteOutline :size="20" />
								</template>
								{{ t('tables', 'Delete application') }}
							</NcActionButton>
						</NcActions>
					</div>
				</header>
				<p class="context__stats" data-cy="context-stats">
					<span v-for="stat in stats" :key="stat.key">
						{{ stat.label }}
					</span>
				</p>

				<section class="context__section" data-cy="context-pages">
					<div class="context__section-header">
						<h2>{{ t('tables', 'Pages') }}</h2>
						<div class="context__section-actions">
							<NcTextField v-if="pages.length > 6"
								v-model="pageFilter"
								:label="t('tables', 'Filter pages')"
								class="context__filter"
								data-cy="context-page-filter" />
							<NcActions v-if="ownsContext(activeContext)" :menu-name="t('tables', 'Add page')" :primary="pages.length === 0" data-cy="context-add-page">
								<template #icon>
									<Plus :size="20" />
								</template>
								<NcActionButton data-cy="context-add-grid-view" @click="addGridView">
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
					<p v-if="pages.length === 0" class="context__hint">
						{{ t('tables', 'No pages yet. Every page gets an entry in the menu of the application.') }}
					</p>
					<p v-else-if="filteredPages.length === 0" class="context__hint">
						{{ t('tables', 'No page matches the filter.') }}
					</p>
					<div v-else class="context__table-wrap">
						<table class="context__table" data-cy="context-pages-table">
							<thead>
								<tr>
									<th scope="col" class="context__col-position">
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
									<th scope="col" class="context__col-actions">
										<span class="hidden-visually">{{ t('tables', 'Actions') }}</span>
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="page in filteredPages"
									:key="page.item.id"
									class="page-row"
									:class="{ 'page-row--child': page.level === 1 }"
									data-cy="page-card">
									<td class="context__col-position">
										{{ page.position }}
									</td>
									<td class="page-row__name">
										<MenuItemIcon :item="page.item" class="page-row__icon" />
										<router-link v-if="page.route" :to="page.route" class="page-row__link" data-cy="page-card-open">
											{{ page.item.label }}
										</router-link>
										<a v-else
											:href="page.item.url"
											target="_blank"
											rel="noopener noreferrer"
											class="page-row__link"
											data-cy="page-card-open">{{ page.item.label }}</a>
									</td>
									<td>{{ page.typeLabel }}</td>
									<td class="page-row__shows">
										{{ page.description }}
									</td>
									<td class="context__col-actions">
										<NcButton v-if="page.kind === 'grid' && canManageElement(page.view)"
											variant="tertiary"
											data-cy="page-card-design"
											@click="openRoute(page.route)">
											<template #icon>
												<PencilOutline :size="20" />
											</template>
											{{ t('tables', 'Design') }}
										</NcButton>
										<NcButton v-else-if="page.route" variant="tertiary" @click="openRoute(page.route)">
											{{ t('tables', 'Open') }}
										</NcButton>
										<NcButton v-else
											variant="tertiary"
											:href="page.item.url"
											target="_blank"
											rel="noopener noreferrer">
											{{ t('tables', 'Open') }}
										</NcButton>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</section>

				<section v-if="ownsContext(activeContext)" class="context__section" data-cy="context-menu-editor">
					<div class="context__section-header">
						<h2>{{ t('tables', 'Menu') }}</h2>
						<div class="context__section-actions">
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
					<p class="context__section-help">
						{{ t('tables', 'The menu is what people see on the left when they open the application. Order it the way they work.') }}
					</p>
					<MenuItemsEditor v-if="menuDraft !== null" ref="menuItemsEditor" v-model:items="menuDraft" />
					<ol v-else-if="menuItems.length > 0" class="context__menu-preview" data-cy="context-menu-preview">
						<li v-for="page in pages"
							:key="page.item.id"
							class="context__menu-preview-item"
							:class="{ 'context__menu-preview-item--child': page.level === 1 }">
							<MenuItemIcon :item="page.item" :size="18" />
							<span>{{ page.item.label }}</span>
							<span class="context__menu-preview-target">{{ page.description }}</span>
						</li>
					</ol>
					<p v-else class="context__hint">
						{{ t('tables', 'The menu is empty. Add a page, or edit the menu to link tables and external links.') }}
					</p>
				</section>

				<section class="context__section" data-cy="context-tables">
					<div class="context__section-header">
						<h2>{{ t('tables', 'Tables') }}</h2>
					</div>
					<p class="context__section-help">
						{{ t('tables', 'The data this application works on. A table is listed when a page, a widget or the application itself uses it.') }}
					</p>
					<p v-if="usedTables.length === 0" class="context__hint">
						{{ t('tables', 'No table is used yet. Add a table page or a widget that shows a table.') }}
					</p>
					<div v-else class="context__table-wrap">
						<table class="context__table" data-cy="context-tables-table">
							<thead>
								<tr>
									<th scope="col">
										{{ t('tables', 'Table') }}
									</th>
									<th scope="col" class="context__col-number">
										{{ t('tables', 'Rows') }}
									</th>
									<th scope="col" class="context__col-number">
										{{ t('tables', 'Views') }}
									</th>
									<th scope="col">
										{{ t('tables', 'Used by') }}
									</th>
									<th scope="col" class="context__col-actions">
										<span class="hidden-visually">{{ t('tables', 'Actions') }}</span>
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="entry in usedTables" :key="entry.id" data-cy="context-table-row">
									<td class="page-row__name">
										<span class="page-row__icon page-row__emoji">{{ entry.table?.emoji || '' }}</span>
										<router-link v-if="entry.table" :to="'/table/' + entry.id" class="page-row__link">
											{{ entry.table.title }}
										</router-link>
										<span v-else>{{ t('tables', 'Table {id}, not accessible to you', { id: entry.id }) }}</span>
									</td>
									<td class="context__col-number">
										{{ entry.table ? entry.table.rowsCount : '' }}
									</td>
									<td class="context__col-number">
										{{ entry.viewCount }}
									</td>
									<td class="page-row__shows">
										{{ entry.usedBy.join(', ') }}
									</td>
									<td class="context__col-actions">
										<NcButton v-if="entry.table" variant="tertiary" :to="'/table/' + entry.id">
											{{ t('tables', 'Open') }}
										</NcButton>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</section>

				<section v-if="ownsContext(activeContext)" class="context__section" data-cy="context-members">
					<div class="context__section-header">
						<h2>{{ t('tables', 'Members') }}</h2>
						<NcButton variant="secondary" data-cy="context-edit-members" @click="editApplication">
							<template #icon>
								<AccountMultipleOutline :size="20" />
							</template>
							{{ t('tables', 'Share with people or groups') }}
						</NcButton>
					</div>
					<p class="context__section-help">
						{{ t('tables', 'Who can open the application. What each member may do with the data follows from the shares of the tables.') }}
					</p>
					<ul class="context__members" data-cy="context-members-list">
						<li v-for="member in members" :key="member.key" class="context__member">
							<NcAvatar v-if="member.type === 'user'" :user="member.id" :display-name="member.name" :size="32" />
							<span v-else class="context__member-group" aria-hidden="true"><AccountMultipleOutline :size="20" /></span>
							<span>{{ member.name }}</span>
							<span class="context__member-role">{{ member.role }}</span>
						</li>
					</ul>
				</section>
			</div>

			<div v-if="!selectedMenuItem && isStandalone" class="resources">
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
import { NcActionButton, NcActionSeparator, NcActions, NcAvatar, NcButton, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import { getCurrentUser } from '@nextcloud/auth'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
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
import GridView from '../modules/grid/GridView.vue'
import MenuItemIcon from '../shared/components/MenuItemIcon.vue'
import MenuItemsEditor from '../shared/components/ncContextResource/MenuItemsEditor.vue'
import permissionsMixin from '../shared/components/ncTable/mixins/permissionsMixin.js'
import { useEventBusSubscriptions } from '../shared/composables/useEventBusSubscriptions.js'
import { areMenuItemsValid, menuItemRoute, menuTree, toEditableMenuItems, toMenuItemPayload } from '../shared/utils/menuItems.js'
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
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	components: {
		MainModals,
		NcActionButton,
		NcActionSeparator,
		NcActions,
		NcAvatar,
		NcButton,
		NcEmptyContent,
		NcIconSvgWrapper,
		NcLoadingIcon,
		NcTextField,
		AccountMultipleOutline,
		ChevronLeft,
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
		GridView,
		MenuItemIcon,
		MenuItemsEditor,
		ErrorMessage,
		TableWrapper,
		CustomView,
	},

	mixins: [exportTableMixin, svgHelper, permissionsMixin],
	setup() {
		const store = useDataStore()
		const { getColumns, getRows } = storeToRefs(store)
		return { getColumns, getRows, ...useEventBusSubscriptions() }
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
			menuDraft: null,
			menuSaving: false,
			pageFilter: '',
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
		/**
		 * The menu items in menu order, each with what it opens, for the pages table and the menu preview.
		 *
		 * @return {Array<object>}
		 */
		pages() {
			const ordered = []
			menuTree(this.menuItems).forEach((branch, rootIndex) => {
				ordered.push({ item: branch.item, position: String(rootIndex + 1), level: 0 })
				branch.children.forEach((child, childIndex) => ordered.push({ item: child, position: (rootIndex + 1) + '.' + (childIndex + 1), level: 1 }))
			})
			return ordered.map(({ item, position, level }) => {
				const page = { item, position, level, route: menuItemRoute(item, this.activeContext.id), view: null }
				if (item.targetType === 'url') {
					return { ...page, kind: 'url', typeLabel: t('tables', 'Link'), description: item.url }
				}
				if (item.broken) {
					return { ...page, kind: 'broken', typeLabel: t('tables', 'Missing'), description: t('tables', 'The view or table this entry opened no longer exists') }
				}
				if (item.targetType === 'table') {
					const table = this.tables.find(table => table.id === item.targetId)
					return { ...page, kind: 'table', typeLabel: t('tables', 'Table'), description: table ? n('tables', '%n row', '%n rows', table.rowsCount ?? 0) : t('tables', 'Not accessible to you') }
				}
				const view = this.views.find(view => view.id === item.targetId)
				if (view?.type === 'grid') {
					return { ...page, kind: 'grid', typeLabel: t('tables', 'Page with widgets'), description: n('tables', '%n widget', '%n widgets', view.grid?.widgets?.length ?? 0), view }
				}
				const table = view ? this.tables.find(table => table.id === view.tableId) : null
				const description = view
					? (table ? t('tables', 'View of {table}', { table: table.title }, undefined, { escape: false }) : view.title)
					: t('tables', 'Not accessible to you')
				return { ...page, kind: 'view', typeLabel: t('tables', 'View'), description, view }
			})
		},
		filteredPages() {
			const query = this.pageFilter.trim().toLowerCase()
			if (query === '') {
				return this.pages
			}
			return this.pages.filter(page => [page.item.label, page.description, page.typeLabel].some(text => (text ?? '').toLowerCase().includes(query)))
		},
		/**
		 * Every table the application touches: through its own nodes, through a page that shows a
		 * table or a view, and through a widget that shows one. Keyed by table id, with who uses it.
		 *
		 * @return {Array<object>}
		 */
		usedTables() {
			const uses = new Map()
			const add = (tableId, usedBy) => {
				if (!tableId) {
					return
				}
				const entry = uses.get(tableId) ?? { id: tableId, usedBy: [] }
				if (!entry.usedBy.includes(usedBy)) {
					entry.usedBy.push(usedBy)
				}
				uses.set(tableId, entry)
			}
			const menuTargets = new Set(this.menuItems.map(item => item.targetType + '-' + item.targetId))
			for (const node of Object.values(this.activeContext?.nodes ?? {})) {
				const nodeType = parseInt(node.node_type)
				if (menuTargets.has((nodeType === NODE_TYPE_TABLE ? 'table-' : 'view-') + node.node_id)) {
					continue
				}
				if (nodeType === NODE_TYPE_TABLE) {
					add(node.node_id, t('tables', 'Application'))
				} else if (nodeType === NODE_TYPE_VIEW) {
					add(this.views.find(view => view.id === node.node_id)?.tableId, t('tables', 'Application'))
				}
			}
			for (const page of this.pages) {
				if (page.kind === 'table') {
					add(page.item.targetId, page.item.label)
				} else if (page.kind === 'view') {
					add(page.view?.tableId, page.item.label)
				} else if (page.kind === 'grid') {
					for (const widget of page.view?.grid?.widgets ?? []) {
						const target = widget.content?.target
						if (!target) {
							continue
						}
						const tableId = target.type === 'table' ? target.id : this.views.find(view => view.id === target.id)?.tableId
						add(tableId, page.item.label)
					}
				}
			}
			return [...uses.values()].map(entry => ({
				...entry,
				table: this.tables.find(table => table.id === entry.id) ?? null,
				viewCount: this.views.filter(view => view.tableId === entry.id && view.type !== 'grid').length,
			})).sort((a, b) => (a.table?.title ?? '').localeCompare(b.table?.title ?? ''))
		},
		/**
		 * The owner and everyone the application is shared with. Only the owner gets every share.
		 *
		 * @return {Array<object>}
		 */
		members() {
			const owner = this.activeContext?.owner
			const currentUser = getCurrentUser()
			const ownerName = owner === currentUser?.uid ? currentUser.displayName : owner
			const list = owner ? [{ key: 'owner', type: 'user', id: owner, name: ownerName, role: t('tables', 'Owner') }] : []
			for (const share of Object.values(this.activeContext?.sharing ?? {})) {
				if (share.receiver === owner && share.receiver_type === 'user') {
					continue
				}
				list.push({
					key: 'share-' + share.share_id,
					type: share.receiver_type,
					id: share.receiver,
					name: share.receiver_display_name ?? share.receiver,
					role: share.receiver_type === 'user' ? t('tables', 'Member') : t('tables', 'Group'),
				})
			}
			return list
		},
		stats() {
			const stats = [
				{ key: 'pages', label: n('tables', '%n page', '%n pages', this.pages.length) },
				{ key: 'tables', label: n('tables', '%n table', '%n tables', this.usedTables.length) },
			]
			// Only the owner sees every share; anyone else would see a partial count
			if (this.ownsContext(this.activeContext)) {
				stats.push({ key: 'members', label: n('tables', '%n member', '%n members', this.members.length) })
			}
			return stats
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
		this.subscribeToEventBus('tables:view:grid-created', this.onGridViewCreated)
		await this.reload()
	},

	methods: {
		...mapActions(useTablesStore, ['loadContext', 'validateExportAccess', 'loadContextTable', 'loadContextView', 'updateContextMenuItems']),
		...mapActions(useDataStore, ['loadColumnsFromBE', 'loadRowsFromBE', 'loadRelationsFromBE']),
		async copyAddress() {
			try {
				await navigator.clipboard.writeText(this.applicationUrl)
				showSuccess(t('tables', 'Address copied'))
			} catch (e) {
				showError(t('tables', 'The address could not be copied. Select it and copy it yourself.'))
			}
		},
		editApplication() {
			emit('tables:context:edit', this.activeContext.id)
		},
		importScheme() {
			emit('tables:context:import-scheme', this.activeContext.id)
		},
		transferApplication() {
			emit('tables:context:transfer', this.activeContext)
		},
		deleteApplication() {
			emit('tables:context:delete', this.activeContext)
		},
		/**
		 * Opens the menu editor, with a fresh item of the given kind appended when asked for.
		 *
		 * @param {string|null} appendKind 'table' or 'url' to start a new entry of that kind
		 */
		editMenu(appendKind = null) {
			const items = toEditableMenuItems(this.menuItems)
			if (appendKind !== null) {
				items.push({ key: 'new-' + Date.now(), label: '', icon: null, targetType: appendKind, targetId: null, url: '', technicalName: null, parentKey: null })
			}
			this.menuDraft = items
			this.$nextTick(() => document.querySelector('[data-cy="context-menu-editor"]')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
		},
		async saveMenu() {
			// Show every error now, and never send a menu the server would refuse
			this.$refs.menuItemsEditor?.touchAll()
			if (this.menuDraft === null || !areMenuItemsValid(this.menuDraft)) {
				return
			}
			this.menuSaving = true
			const context = await this.updateContextMenuItems({ id: this.activeContext.id, menuItems: this.menuDraft.map(item => toMenuItemPayload(item, this.menuDraft)) })
			this.menuSaving = false
			if (context) {
				this.menuDraft = null
				showSuccess(t('tables', 'Menu saved'))
			}
		},
		addGridView() {
			emit('tables:view:create-grid', { contextId: this.activeContext.id })
		},
		/**
		 * A grid view created from this application gets a menu item right away, and opens.
		 *
		 * @param {{view: object, contextId: number|null}} payload the created view and the application it was created from
		 */
		async onGridViewCreated({ view, contextId }) {
			if (!view || contextId !== this.activeContext?.id) {
				return
			}
			const menuItems = [
				...this.menuItems.map(item => ({
					label: item.label,
					icon: item.icon,
					targetType: item.targetType,
					targetId: item.targetId,
					url: item.url,
					technicalName: item.technicalName,
					parentIndex: item.parentId != null ? this.menuItems.findIndex(candidate => candidate.id === item.parentId) : null,
				})),
				{ label: view.title, icon: null, targetType: 'view', targetId: view.id, url: null, technicalName: null, parentIndex: null },
			]
			const context = await this.updateContextMenuItems({ id: this.activeContext.id, menuItems })
			const added = context?.menuItems?.find(item => item.targetType === 'view' && item.targetId === view.id)
			if (added) {
				await this.$router.push(menuItemRoute(added, this.activeContext.id)).catch(err => err)
			}
		},
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
	&__overview {
		width: var(--app-content-width, 100%);
	}

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

	&__stats {
		display: flex;
		flex-wrap: wrap;
		gap: calc(4 * var(--default-grid-baseline));
		margin: 0;
		padding-block: calc(2 * var(--default-grid-baseline));
		padding-inline: calc(var(--default-clickable-area) + 4 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
		border-bottom: 1px solid var(--color-border);
		color: var(--color-text-maxcontrast);
	}

	&__filter {
		max-width: 260px;
	}

	&__col-number {
		width: 1%;
		font-variant-numeric: tabular-nums;
		text-align: end;
		white-space: nowrap;
	}

	&__members {
		display: flex;
		flex-wrap: wrap;
		gap: calc(2 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__member {
		display: flex;
		align-items: center;
		gap: calc(2 * var(--default-grid-baseline));
		min-width: 200px;
	}

	&__member-group {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 32px;
		height: 32px;
		border-radius: 50%;
		background-color: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
	}

	&__member-role {
		color: var(--color-text-maxcontrast);
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
		padding-inline-start: calc(6 * var(--default-grid-baseline));
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

	&__menu-preview-target {
		color: var(--color-text-maxcontrast);
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

}

.page-row {
	&--child &__name {
		padding-inline-start: calc(6 * var(--default-grid-baseline));
	}

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

	&__emoji {
		width: 20px;
		text-align: center;
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
	.context__stats,
	.context__section {
		padding-inline-start: calc(4 * var(--default-grid-baseline));
	}

	.context__header-actions {
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
