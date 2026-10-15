<!--
  - SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="row main-context-view">
		<div v-if="loading" class="icon-loading" />

		<div v-else-if="activeContext && context">
			<div class="content context">
				<div class="row first-row">
					<h1 class="context__title" data-cy="context-title">
						<NcIconSvgWrapper :svg="icon" :size="32" style="display: inline-block;" />&nbsp; {{
							activeContext.name }}
					</h1>
				</div>
				<div class="row space-L context__description">
					{{ activeContext.description }}
				</div>
				<nav v-if="pages.length > 1 || canManage" class="context__pages" :aria-label="t('tables', 'Pages')" data-cy="contextPages">
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
			</div>

			<GridPage v-if="activePage"
				:key="activePage.id"
				:context="context"
				:page="activePage"
				:can-edit="canManage"
				:start-in-edit-mode="$route.query.edit === '1'"
				@saved="onPageSaved" />
			<ErrorMessage v-else :message="t('tables', 'This page could not be found')" />

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
import { NcActionButton, NcActions, NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import { showConfirmation } from '@nextcloud/dialogs'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { emit } from '@nextcloud/event-bus'
import svgHelper from '../shared/components/ncIconPicker/mixins/svgHelper.js'
import permissionsMixin from '../shared/components/ncTable/mixins/permissionsMixin.js'
import { useTablesStore } from '../store/store.js'
import ErrorMessage from '../modules/main/partials/ErrorMessage.vue'
import GridPage from '../modules/grid/GridPage.vue'
import ContextPageForm from '../modules/modals/ContextPageForm.vue'
import displayError, { getNotFoundError, getGenericLoadError } from '../shared/utils/displayError.js'

/**
 * An application: its title and description, and its pages. The start page is the
 * dashboard; every page shows its widgets on a grid.
 */
export default {
	components: {
		MainModals,
		NcActionButton,
		NcActions,
		NcButton,
		NcIconSvgWrapper,
		PencilOutline,
		Plus,
		TrashCanOutline,
		ErrorMessage,
		GridPage,
		ContextPageForm,
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
		}
	},

	computed: {
		...mapState(useTablesStore, ['contexts', 'activeContextId', 'activeContext']),
		canManage() {
			return this.ownsContext(this.context)
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
		emit('toggle-navigation', {
			open: false,
		})
		await this.reload()
	},

	methods: {
		...mapActions(useTablesStore, ['loadContext', 'loadContextPages', 'createContextPage', 'updateContextPage', 'deleteContextPage']),
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
		 * @return {string} the route of the page; the start page is the application itself
		 */
		pageRoute(page) {
			return page.page_type === 'startpage'
				? '/application/' + this.context.id
				: '/application/' + this.context.id + '/page/' + page.id
		},
		/**
		 * @param {object} page a page of the application
		 * @return {string} its title; the start page is the dashboard unless it was named
		 */
		pageTitle(page) {
			return page.title || (page.page_type === 'startpage' ? t('tables', 'Dashboard') : t('tables', 'Untitled page'))
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

	&__description {
		margin: calc(3 * var(--default-grid-baseline, 4px));
		max-width: 790px;
		margin-inline-start: 32px;
	}

	&__pages {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: var(--default-grid-baseline);
		margin-inline-start: calc(8 * var(--default-grid-baseline));
	}

	&:deep(.icon-vue) {
		min-width: 32px;
		min-height: 32px;
	}
}

.main-context-view {
	width: 100%;
}
</style>
