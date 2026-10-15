<!--
  - SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="row main-context-view">
		<div v-if="loading" class="icon-loading" />

		<div v-else-if="activeContext && context" :class="{ 'context--standalone': isStandalone }">
			<ApplicationOverview v-if="isOverview"
				:context="activeContext"
				:pages="pages"
				:icon="icon"
				:can-manage="canManage"
				@page-created="onPageCreated" />

			<div v-if="!isOverview && !isStandalone" class="context__breadcrumb" data-cy="context-breadcrumb">
				<NcButton variant="tertiary" data-cy="context-menu-overview" @click="openRoute('/application/' + context.id)">
					<template #icon>
						<ChevronLeft :size="20" />
					</template>
					{{ activeContext.name }}
				</NcButton>
				<span class="context__breadcrumb-separator" aria-hidden="true">/</span>
				<span class="context__breadcrumb-current">{{ breadcrumbLabel }}</span>
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

			<nav v-if="!isOverview && !isMenuRoute && !isStandalone && (pages.length > 1 || canManage)"
				class="context__pages"
				:aria-label="t('tables', 'Pages')"
				data-cy="contextPages">
				<NcButton v-for="page in pages"
					:key="page.id"
					:variant="activePage && page.id === activePage.id ? 'primary' : 'tertiary'"
					:to="pageRoute(page)"
					:aria-current="activePage && page.id === activePage.id ? 'page' : undefined"
					data-cy="contextPageLink">
					{{ pageTitle(page) }}
				</NcButton>
				<NcButton v-if="canManage" variant="tertiary" data-cy="contextPageCreate" @click="openPageForm(null)">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('tables', 'New page') }}
				</NcButton>
				<NcActions v-if="canManage && activePage && activePage.page_type !== 'startpage'"
					:aria-label="t('tables', 'Page actions')"
					data-cy="contextPageActions">
					<NcActionButton :close-after-click="true" data-cy="contextPageRename" @click="openPageForm(activePage)">
						<template #icon>
							<PencilOutline :size="20" />
						</template>
						{{ t('tables', 'Rename page') }}
					</NcActionButton>
					<NcActionButton :close-after-click="true" data-cy="contextPageDelete" @click="deletePage(activePage)">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('tables', 'Delete page') }}
					</NcActionButton>
				</NcActions>
			</nav>

			<div v-if="isMenuRoute" class="context__menu-target" data-cy="context-menu-target">
				<NcEmptyContent v-if="!menuTarget"
					:name="t('tables', 'This menu item points at nothing')"
					:description="t('tables', 'The page, view or table it opened no longer exists, or you have no access to it.')"
					data-cy="context-menu-target-missing" />
				<GridPage v-else-if="menuTarget.page"
					:key="'page-' + menuTarget.page.id"
					:context="context"
					:page="menuTarget.page"
					:can-edit="canManage"
					@saved="onPageSaved" />
				<DataWidget v-else :key="menuTarget.data.type + '-' + menuTarget.data.id" :target="menuTarget.data" />
			</div>
			<template v-else-if="!isOverview">
				<GridPage v-if="activePage"
					:key="activePage.id"
					:context="context"
					:page="activePage"
					:can-edit="canManage"
					:start-in-edit-mode="$route.query.edit === '1'"
					@saved="onPageSaved" />
				<ErrorMessage v-else :message="t('tables', 'This page could not be found')" />
			</template>

			<ContextPageForm :show-modal="pageFormOpen"
				:page="pageBeingRenamed"
				@close="closePageForm"
				@submit="submitPageForm" />
		</div>

		<ErrorMessage v-else-if="errorMessage" :message="errorMessage" />

		<MainModals />
	</div>
</template>

<script>
import MainModals from '../modules/modals/Modals.vue'
import { mapState, mapActions } from 'pinia'
import { NcActionButton, NcActions, NcButton, NcEmptyContent } from '@nextcloud/vue'
import { showConfirmation } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import svgHelper from '../shared/components/ncIconPicker/mixins/svgHelper.js'
import permissionsMixin from '../shared/components/ncTable/mixins/permissionsMixin.js'
import { useTablesStore } from '../store/store.js'
import ErrorMessage from '../modules/main/partials/ErrorMessage.vue'
import GridPage from '../modules/grid/GridPage.vue'
import DataWidget from '../modules/grid/widgets/DataWidget.vue'
import ContextPageForm from '../modules/modals/ContextPageForm.vue'
import ApplicationOverview from '../modules/main/sections/ApplicationOverview.vue'
import displayError, { getNotFoundError, getGenericLoadError } from '../shared/utils/displayError.js'
import { menuItemRoute, pageTitle } from '../shared/utils/menuItems.js'

/**
 * An application: its title and description, and its pages. The start page is the
 * dashboard; every page shows its widgets on a grid. A menu item route opens what the
 * item points at. Opened on its own, at /apps/tables/app/{id}, the application's menu
 * is the navigation and it starts on its first menu item.
 */
export default {
	components: {
		MainModals,
		NcActionButton,
		NcActions,
		NcButton,
		NcEmptyContent,
		ChevronLeft,
		OpenInNew,
		PencilOutline,
		Plus,
		TrashCanOutline,
		ErrorMessage,
		GridPage,
		DataWidget,
		ContextPageForm,
		ApplicationOverview,
	},

	mixins: [svgHelper, permissionsMixin],

	data() {
		return {
			loading: true,
			icon: null,
			context: null,
			pages: [],
			errorMessage: null,
			loadedSignature: null,
			isReloading: false,
			pageFormOpen: false,
			pageBeingRenamed: null,
			standaloneContextId: loadState('tables', 'contextId', null),
			openedFirstPage: false,
		}
	},

	computed: {
		...mapState(useTablesStore, ['contexts', 'activeContextId', 'activeContext']),
		canManage() {
			return this.ownsContext(this.context)
		},
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
			// Technical names are unique per owner only, so an application of someone else is addressed by id
			const technicalName = this.activeContext && this.ownsContext(this.activeContext) ? this.activeContext.technicalName : null
			return window.location.origin + generateUrl('/apps/tables/app/' + (technicalName || this.activeContext?.id))
		},
		isMenuRoute() {
			return !!this.$route.params.itemName
		},
		/**
		 * Inside Tables the application opens on its overview, where it is managed.
		 *
		 * @return {boolean}
		 */
		isOverview() {
			return !this.isStandalone && !this.isMenuRoute && this.$route.params.pageId === undefined
		},
		breadcrumbLabel() {
			if (this.isMenuRoute) {
				return this.selectedMenuItem ? this.selectedMenuItem.label : this.$route.params.itemName
			}
			return this.activePage ? pageTitle(this.activePage) : ''
		},
		selectedMenuItem() {
			const technicalName = this.$route.params.itemName
			if (!technicalName) {
				return null
			}
			return this.menuItems.find(item => item.technicalName === technicalName) ?? null
		},
		/**
		 * What the selected menu item opens: a page of the application, or a table or view.
		 * Null when the item is unknown, broken or a link.
		 *
		 * @return {{page: object}|{data: {type: string, id: number}}|null}
		 */
		menuTarget() {
			const item = this.selectedMenuItem
			if (!item || item.broken || item.targetType === 'url') {
				return null
			}
			if (item.targetType === 'page') {
				const page = this.pages.find(page => page.id === item.targetId)
				return page ? { page } : null
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
		activePage() {
			const pageId = this.$route.params.pageId
			if (pageId === undefined) {
				return this.pages.find(page => page.page_type === 'startpage') ?? null
			}
			return this.pages.find(page => page.id === parseInt(pageId)) ?? null
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
		'context.iconName': {
			async handler(value) {
				this.icon = value ? await this.getContextIcon(value) : ''
			},
			immediate: true,
		},
	},

	async mounted() {
		await this.reload()
		this.openFirstPageWhenStandalone()
	},

	methods: {
		...mapActions(useTablesStore, ['loadContext', 'loadContextPages', 'createContextPage', 'updateContextPage', 'deleteContextPage']),
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
			if (this.$route.params.itemName || this.$route.params.pageId) {
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
			if (!this.activeContextId || this.isReloading) {
				return
			}
			this.isReloading = true
			this.loading = true

			try {
				await this.loadContext({ id: this.activeContextId })
				this.context = this.contexts.find(c => parseInt(c.id) === parseInt(this.activeContextId)) ?? null

				if (!this.context) {
					this.errorMessage = t('tables', 'This application could not be found')
					return
				}

				this.loadedSignature = this.contextSignature()
				this.icon = await this.getContextIcon(this.activeContext.iconName)
				this.pages = await this.loadContextPages({ contextId: this.context.id }) ?? []
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
		 * @param {object} page a page of the application
		 * @return {string} the route of the page
		 */
		pageRoute(page) {
			return '/application/' + this.context.id + '/page/' + page.id
		},
		pageTitle,
		/**
		 * Takes over a page created on the overview.
		 *
		 * @param {object} page the new page
		 */
		onPageCreated(page) {
			this.pages = [...this.pages, page]
		},
		/**
		 * Takes over a saved page. Saving can add tables or views to the application, which the
		 * store loaded again; the signature is updated so that does not reload the whole page.
		 *
		 * @param {object} saved the page as saved
		 */
		onPageSaved(saved) {
			this.context = this.contexts.find(c => parseInt(c.id) === parseInt(this.activeContextId)) ?? this.context
			this.loadedSignature = this.contextSignature()
			this.pages = this.pages.map(page => page.id === saved.id ? saved : page)
		},
		openPageForm(page) {
			this.pageBeingRenamed = page
			this.pageFormOpen = true
		},
		closePageForm() {
			this.pageFormOpen = false
			this.pageBeingRenamed = null
		},
		/**
		 * Creates a page and opens it, or renames the page the form was opened for.
		 *
		 * @param {{title: string, technicalName: string}} data what the form collected
		 */
		async submitPageForm({ title, technicalName }) {
			if (this.pageBeingRenamed) {
				const page = await this.updateContextPage({
					contextId: this.context.id,
					pageId: this.pageBeingRenamed.id,
					data: { title, technicalName },
				})
				if (page) {
					this.pages = this.pages.map(item => item.id === page.id ? page : item)
					this.closePageForm()
				}
				return
			}
			const page = await this.createContextPage({ contextId: this.context.id, title, technicalName: technicalName || null })
			if (page) {
				this.pages = [...this.pages, page]
				this.closePageForm()
				await this.$router.push(this.pageRoute(page))
			}
		},
		/**
		 * Deletes a grid page after asking, and goes back to the start page.
		 *
		 * @param {object} page the page to delete
		 */
		async deletePage(page) {
			let confirmed = false
			try {
				confirmed = await showConfirmation({
					name: t('tables', 'Delete page?'),
					text: t('tables', 'The page "{title}" and its widgets are deleted. The tables and views stay in the application.', { title: this.pageTitle(page) }),
					labelConfirm: t('tables', 'Delete page'),
					labelReject: t('tables', 'Cancel'),
				})
			} catch {
				// Closing the dialog keeps the page
			}
			if (!confirmed || !await this.deleteContextPage({ contextId: this.context.id, pageId: page.id })) {
				return
			}
			this.pages = this.pages.filter(item => item.id !== page.id)
			await this.$router.push('/application/' + this.context.id)
		},
	},
}

</script>

<style scoped lang="scss">
.context {
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
		width: 100%;
		padding-block: calc(2 * var(--default-grid-baseline));
	}

	&__pages {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: var(--default-grid-baseline);
		padding-block: calc(2 * var(--default-grid-baseline));
		padding-inline: calc(var(--default-clickable-area) + 4 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
	}
}

.main-context-view {
	width: 100%;
}

.context--standalone {
	width: 100%;
	// Leave room for the navigation toggle that floats over the top left corner of the content
	padding-inline-start: var(--default-clickable-area);
}
</style>
