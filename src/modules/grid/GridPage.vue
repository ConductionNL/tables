<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="grid-page" data-cy="gridPage">
		<div v-if="canEdit || personalLayout" class="grid-page__header">
			<div class="grid-page__actions">
				<template v-if="isEditingWidths">
					<NcButton v-if="Object.keys(widthDraft).length > 0" variant="tertiary" data-cy="context-layout-reset" @click="widthDraft = {}">
						<template #icon>
							<Restore :size="20" />
						</template>
						{{ t('tables', 'Reset layout') }}
					</NcButton>
					<NcButton variant="tertiary" data-cy="context-layout-cancel" @click="widthDraft = null">
						{{ t('tables', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary" data-cy="context-layout-save" @click="saveWidths">
						<template #icon>
							<ContentSaveOutline :size="20" />
						</template>
						{{ t('tables', 'Save') }}
					</NcButton>
				</template>
				<template v-else-if="isEditing">
					<NcButton variant="secondary" data-cy="gridPageAddWidget" @click="openAddWidget">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('tables', 'Add widget') }}
					</NcButton>
					<NcButton variant="tertiary" data-cy="gridPageCancel" @click="cancelEditing">
						{{ t('tables', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary" :disabled="saving" data-cy="gridPageSave" @click="save">
						<template #icon>
							<ContentSaveOutline :size="20" />
						</template>
						{{ t('tables', 'Save') }}
					</NcButton>
				</template>
				<template v-else>
					<NcButton v-if="personalLayout && shownLayout.length > 0"
						variant="tertiary"
						data-cy="context-layout-edit"
						@click="widthDraft = { ...widths }">
						<template #icon>
							<TableColumnWidth :size="20" />
						</template>
						{{ t('tables', 'Edit layout') }}
					</NcButton>
					<NcButton v-if="canEdit" variant="secondary" data-cy="gridPageEdit" @click="startEditing">
						<template #icon>
							<PencilOutline :size="20" />
						</template>
						{{ t('tables', 'Edit page') }}
					</NcButton>
				</template>
			</div>
		</div>

		<NcEmptyContent v-if="activeGrid.layout.length === 0"
			class="grid-page__empty"
			:name="t('tables', 'This page is empty')"
			:description="isEditing ? t('tables', 'Add a header, a description or a table.') : t('tables', 'Edit the page to add widgets.')">
			<template #icon>
				<ViewDashboardOutline />
			</template>
			<template v-if="isEditing" #action>
				<NcButton variant="primary" @click="openAddWidget">
					{{ t('tables', 'Add widget') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<GridCanvas :layout="shownLayout" :editable="isEditing" @layout-change="onLayoutChange">
			<template #widget="{ item, narrow }">
				<GridWidget v-if="widgetsById[item.widgetId]"
					:widget="widgetsById[item.widgetId]"
					:layout-item="item"
					:editable="isEditing"
					:arrangeable="!narrow"
					:width-options="isEditingWidths ? widthOptions : null"
					:width="item.gridWidth"
					@set-width="setWidth"
					@configure="configureWidget"
					@remove="removeWidget"
					@move="moveWidget" />
			</template>
		</GridCanvas>

		<AddWidget :show-modal="addWidgetOpen"
			:editing-widget="widgetBeingConfigured"
			@close="closeAddWidget"
			@submit="onWidgetSubmit" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import { showConfirmation, showError, showSuccess } from '@nextcloud/dialogs'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import TableColumnWidth from 'vue-material-design-icons/TableColumnWidth.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import { computed, ref } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import { mapActions, mapState } from 'pinia'
import GridCanvas from './GridCanvas.vue'
import GridWidget from './GridWidget.vue'
import AddWidget from '../modals/AddWidget.vue'
import { useTablesStore } from '../../store/store.js'
import { applyPersonalWidths, loadContextLayout, saveContextLayout } from '../../shared/utils/contextLayout.js'
import { GRID_COLUMNS, GRID_MIN_WIDTH, cloneGrid, findWidgetType, gridToWidgets, nextFreeRow, pageToGrid } from './gridLayout.js'

/**
 * A page of an application: its widgets on a grid, with an edit mode that saves the
 * whole page at once.
 */
export default {
	name: 'GridPage',
	components: {
		NcButton,
		NcEmptyContent,
		ContentSaveOutline,
		PencilOutline,
		Plus,
		Restore,
		TableColumnWidth,
		ViewDashboardOutline,
		GridCanvas,
		GridWidget,
		AddWidget,
	},
	props: {
		// The application, for its id and its nodes
		context: {
			type: Object,
			required: true,
		},
		page: {
			type: Object,
			required: true,
		},
		canEdit: {
			type: Boolean,
			default: false,
		},
		// Opens the page in edit mode, for a link from the application settings
		startInEditMode: {
			type: Boolean,
			default: false,
		},
		// Lets the user choose their own widths of the widgets, kept in this browser
		personalLayout: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['saved'],
	setup(props) {
		// The draft lives here, so the route guards and the component share one implementation
		const draftGrid = ref(null)
		const storedGrid = computed(() => pageToGrid(props.page, props.context.nodes))
		const hasUnsavedChanges = computed(() => draftGrid.value !== null && JSON.stringify(draftGrid.value) !== JSON.stringify(cloneGrid(storedGrid.value)))

		/**
		 * Asks whether unsaved changes may be discarded.
		 *
		 * @return {Promise<boolean>} true when there is nothing to lose or the user agrees
		 */
		const confirmDiscard = async () => {
			if (!hasUnsavedChanges.value) {
				return true
			}
			try {
				return await showConfirmation({
					name: t('tables', 'Discard unsaved changes?'),
					text: t('tables', 'This page has changes that are not saved yet.'),
					labelConfirm: t('tables', 'Discard changes'),
					labelReject: t('tables', 'Keep editing'),
				})
			} catch {
				// Closing the dialog keeps the changes
				return false
			}
		}

		// Leaving the page, or switching to another page, asks first while the grid has unsaved changes
		onBeforeRouteLeave(confirmDiscard)
		onBeforeRouteUpdate(confirmDiscard)

		return { draftGrid, storedGrid, hasUnsavedChanges, confirmDiscard }
	},
	data() {
		return {
			saving: false,
			addWidgetOpen: false,
			widgetBeingConfigured: null,
			// The user's own widths of the widgets, and the ones being edited
			widths: {},
			widthDraft: null,
		}
	},
	computed: {
		...mapState(useTablesStore, ['widgetTypes']),
		activeGrid() {
			return this.draftGrid ?? this.storedGrid
		},
		isEditing() {
			return this.draftGrid !== null
		},
		isEditingWidths() {
			return this.widthDraft !== null
		},
		/**
		 * The layout on screen: the draft while the page is edited, else the page as arranged with
		 * the user's own widths.
		 *
		 * @return {Array}
		 */
		shownLayout() {
			if (this.isEditing || !this.personalLayout) {
				return this.activeGrid.layout
			}
			return applyPersonalWidths(this.storedGrid.layout, this.widthDraft ?? this.widths)
		},
		widthOptions() {
			return [
				{ span: 12, label: t('tables', 'Full width') },
				{ span: 8, label: t('tables', 'Two thirds') },
				{ span: 6, label: t('tables', 'Half') },
				{ span: 4, label: t('tables', 'One third') },
			]
		},
		widgetsById() {
			return Object.fromEntries(this.activeGrid.widgets.map(widget => [widget.id, widget]))
		},
	},
	watch: {
		'page.id'() {
			this.draftGrid = null
			this.loadWidths()
		},
		startInEditMode(startInEditMode) {
			if (startInEditMode && this.canEdit && !this.isEditing) {
				this.startEditing()
			}
		},
	},
	mounted() {
		this.loadWidths()
		window.addEventListener('beforeunload', this.warnBeforeUnload)
		if (this.startInEditMode && this.canEdit) {
			this.startEditing()
		}
	},
	beforeUnmount() {
		window.removeEventListener('beforeunload', this.warnBeforeUnload)
	},
	methods: {
		...mapActions(useTablesStore, ['saveContextPageWidgets']),
		/**
		 * Lets the browser warn before the tab closes or reloads with unsaved changes.
		 *
		 * @param {BeforeUnloadEvent} event the unload event
		 */
		warnBeforeUnload(event) {
			if (this.hasUnsavedChanges) {
				event.preventDefault()
			}
		},
		loadWidths() {
			this.widths = this.personalLayout ? loadContextLayout(this.context.id, this.page.id) : {}
			this.widthDraft = null
		},
		/**
		 * Sets the user's own width of one widget; the full width of the page as arranged is no choice
		 * of its own, so it is not kept.
		 *
		 * @param {string} widgetId the widget
		 * @param {number} span the number of columns
		 */
		setWidth(widgetId, span) {
			const draft = { ...this.widthDraft }
			const arranged = this.storedGrid.layout.find(item => item.widgetId === widgetId)
			if (arranged && arranged.gridWidth === span) {
				delete draft[widgetId]
			} else {
				draft[widgetId] = span
			}
			this.widthDraft = draft
		},
		/**
		 * Keeps the widths being edited for this user in this browser.
		 */
		saveWidths() {
			if (this.widthDraft === null) {
				return
			}
			this.widths = this.widthDraft
			this.widthDraft = null
			try {
				saveContextLayout(this.context.id, this.page.id, this.widths)
			} catch (error) {
				console.error('The application layout could not be stored', error)
				showError(t('tables', 'Your layout applies to this visit only, as this browser does not allow storing it.'))
			}
		},
		/**
		 * Starts editing on a copy of the stored grid.
		 */
		startEditing() {
			this.draftGrid = cloneGrid(this.storedGrid)
		},
		/**
		 * Leaves edit mode and drops the draft.
		 */
		cancelEditing() {
			this.draftGrid = null
		},
		/**
		 * Takes over the layout after a drag or resize.
		 *
		 * @param {Array} layout the new layout
		 */
		onLayoutChange(layout) {
			if (!this.isEditing) {
				return
			}
			this.draftGrid = { ...this.draftGrid, layout }
		},
		/**
		 * Opens the dialog for a new widget.
		 */
		openAddWidget() {
			this.widgetBeingConfigured = null
			this.addWidgetOpen = true
		},
		/**
		 * Closes the widget dialog.
		 */
		closeAddWidget() {
			this.addWidgetOpen = false
			this.widgetBeingConfigured = null
		},
		/**
		 * Opens the dialog for a widget on the grid.
		 *
		 * @param {object} widget the widget to configure
		 */
		configureWidget(widget) {
			this.widgetBeingConfigured = widget
			this.addWidgetOpen = true
		},
		/**
		 * Updates the configured widget, or places a new one below the others.
		 *
		 * @param {{type: string, configuration: object, content: object, node: object|null}} payload the widget from the dialog
		 */
		onWidgetSubmit(payload) {
			if (this.widgetBeingConfigured) {
				this.draftGrid = {
					...this.draftGrid,
					widgets: this.draftGrid.widgets.map(widget => widget.id === this.widgetBeingConfigured.id
						? { ...widget, configuration: payload.configuration, content: payload.content, node: payload.node }
						: widget),
				}
				this.closeAddWidget()
				return
			}
			const id = 'w-' + payload.type + '-' + Date.now()
			const widgetType = findWidgetType(this.widgetTypes, payload.type)
			const size = { gridWidth: widgetType?.defaultWidth ?? 6, gridHeight: widgetType?.defaultHeight ?? 3 }
			this.draftGrid = {
				widgets: [...this.draftGrid.widgets, { id, type: payload.type, nodeRelId: null, node: payload.node, configuration: payload.configuration, content: payload.content }],
				layout: [...this.draftGrid.layout, {
					widgetId: id,
					gridX: 0,
					gridY: nextFreeRow(this.draftGrid.layout),
					gridWidth: size.gridWidth,
					gridHeight: size.gridHeight,
				}],
			}
			this.closeAddWidget()
		},
		/**
		 * Moves or resizes a widget by one step, kept inside the grid.
		 *
		 * @param {object} widget the widget to change
		 * @param {{gridX?: number, gridY?: number, gridWidth?: number, gridHeight?: number}} change the steps to apply
		 */
		moveWidget(widget, change) {
			this.draftGrid = {
				...this.draftGrid,
				layout: this.draftGrid.layout.map(item => {
					if (item.widgetId !== widget.id) {
						return item
					}
					const gridWidth = Math.min(GRID_COLUMNS, Math.max(GRID_MIN_WIDTH, item.gridWidth + (change.gridWidth ?? 0)))
					return {
						...item,
						gridWidth,
						gridHeight: Math.max(1, item.gridHeight + (change.gridHeight ?? 0)),
						gridX: Math.min(GRID_COLUMNS - gridWidth, Math.max(0, item.gridX + (change.gridX ?? 0))),
						gridY: Math.max(0, item.gridY + (change.gridY ?? 0)),
					}
				}),
			}
		},
		/**
		 * Removes a widget and its place on the grid.
		 *
		 * @param {object} widget the widget to remove
		 */
		removeWidget(widget) {
			this.draftGrid = {
				widgets: this.draftGrid.widgets.filter(item => item.id !== widget.id),
				layout: this.draftGrid.layout.filter(item => item.widgetId !== widget.id),
			}
		},
		/**
		 * Saves the whole grid at once.
		 */
		async save() {
			if (!this.isEditing) {
				return
			}
			this.saving = true
			const page = await this.saveContextPageWidgets({
				contextId: this.context.id,
				pageId: this.page.id,
				widgets: gridToWidgets(this.draftGrid, this.context.nodes),
			})
			this.saving = false
			// A failed save is already reported by the store
			if (page) {
				this.draftGrid = null
				this.$emit('saved', page)
				showSuccess(t('tables', 'Page saved'))
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.grid-page {
	width: 100%;
	padding: calc(4 * var(--default-grid-baseline));

	&__header {
		display: flex;
		align-items: flex-start;
		justify-content: flex-end;
		gap: calc(4 * var(--default-grid-baseline));
		margin-bottom: calc(4 * var(--default-grid-baseline));
	}

	&__actions {
		display: flex;
		align-items: center;
		gap: calc(2 * var(--default-grid-baseline));
		flex-shrink: 0;
	}

	&__empty {
		margin-block: 4vh;
	}
}
</style>
