<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="menu-items-editor" data-cy="menuItemsEditor">
		<p class="menu-items-editor__help">
			{{ t('tables', 'Drag an entry to move it. An entry can sit under the entry above it, one level deep.') }}
		</p>
		<ul v-if="items.length > 0" class="menu-items-editor__list">
			<li v-for="(item, index) in items"
				:key="item.key"
				class="menu-items-editor__item"
				:class="{ 'menu-items-editor__item--child': item.parentKey !== null, 'menu-items-editor__item--dragging': draggedIndex === index }"
				draggable="true"
				data-cy="menuItemRow"
				@dragstart="dragStart(index, $event)"
				@dragover.prevent="dragOver(index)"
				@dragend="dragEnd">
				<span class="menu-items-editor__handle" :aria-label="t('tables', 'Drag to move')" :title="t('tables', 'Drag to move')">
					<DragHorizontalVariant :size="20" />
				</span>
				<NcIconPicker :close-on-select="true" @select="iconName => update(index, { icon: iconName })">
					<NcButton variant="tertiary" :aria-label="t('tables', 'Choose an icon')" :title="t('tables', 'Choose an icon')" data-cy="menuItemIcon">
						<template #icon>
							<ApplicationIcon v-if="item.icon" :icon-name="item.icon" :size="20" />
							<DotsHorizontalCircleOutline v-else :size="20" />
						</template>
					</NcButton>
				</NcIconPicker>
				<NcTextField :model-value="item.label"
					:label="t('tables', 'Label')"
					data-cy="menuItemLabel"
					@update:model-value="value => update(index, { label: value })" />
				<NcSelect :model-value="targetOption(item)"
					:input-label="t('tables', 'Opens')"
					:options="targetOptions"
					:clearable="false"
					:searchable="true"
					label="label"
					class="menu-items-editor__target"
					data-cy="menuItemTarget"
					@update:model-value="option => setTarget(index, option)" />
				<NcTextField v-if="item.targetType === 'url'"
					:model-value="item.url ?? ''"
					:label="t('tables', 'Link')"
					placeholder="https://"
					@update:model-value="value => update(index, { url: value })" />
				<div class="menu-items-editor__row-actions">
					<NcButton v-if="item.parentKey === null"
						:disabled="!canIndent(index)"
						:aria-label="t('tables', 'Move under the entry above')"
						:title="t('tables', 'Move under the entry above')"
						variant="tertiary"
						data-cy="menuItemIndent"
						@click="indent(index)">
						<template #icon>
							<FormatIndentIncrease :size="20" />
						</template>
					</NcButton>
					<NcButton v-else
						:aria-label="t('tables', 'Move to the top level')"
						:title="t('tables', 'Move to the top level')"
						variant="tertiary"
						data-cy="menuItemOutdent"
						@click="outdent(index)">
						<template #icon>
							<FormatIndentDecrease :size="20" />
						</template>
					</NcButton>
					<NcButton :aria-label="t('tables', 'Remove menu item')" variant="tertiary" data-cy="menuItemRemove" @click="remove(index)">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
					</NcButton>
				</div>
			</li>
		</ul>
		<p v-else class="menu-items-editor__empty">
			{{ t('tables', 'No menu items yet') }}
		</p>
		<NcButton variant="secondary" data-cy="menuItemAdd" @click="add">
			<template #icon>
				<Plus :size="20" />
			</template>
			{{ t('tables', 'Add menu item') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import DotsHorizontalCircleOutline from 'vue-material-design-icons/DotsHorizontalCircleOutline.vue'
import DragHorizontalVariant from 'vue-material-design-icons/DragHorizontalVariant.vue'
import FormatIndentDecrease from 'vue-material-design-icons/FormatIndentDecrease.vue'
import FormatIndentIncrease from 'vue-material-design-icons/FormatIndentIncrease.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { mapState } from 'pinia'
import ApplicationIcon from '../ApplicationIcon.vue'
import NcIconPicker from '../ncIconPicker/NcIconPicker.vue'
import { useTablesStore } from '../../../store/store.js'

const URL_OPTION_ID = 'url'

/**
 * Edits the ordered menu of an application. Every item opens a view, a table or a link, carries an
 * icon, and can sit under the top-level item above it: one level of nesting.
 *
 * The list is flat and ordered; a child carries the key of its parent. A parent always precedes its
 * children, and a parent that moves takes its children along.
 */
export default {
	name: 'MenuItemsEditor',
	components: {
		ApplicationIcon,
		NcButton,
		NcIconPicker,
		NcSelect,
		NcTextField,
		DotsHorizontalCircleOutline,
		DragHorizontalVariant,
		FormatIndentDecrease,
		FormatIndentIncrease,
		Plus,
		TrashCanOutline,
	},
	props: {
		items: {
			type: Array,
			default: () => ([]),
		},
	},
	emits: ['update:items'],
	data() {
		return {
			draggedIndex: null,
		}
	},
	computed: {
		...mapState(useTablesStore, ['tables', 'views']),
		targetOptions() {
			const views = this.views.map(view => ({
				id: 'view-' + view.id,
				targetType: 'view',
				targetId: view.id,
				label: (view.emoji ? view.emoji + ' ' : '') + view.title + (view.type === 'grid' ? ' (' + t('tables', 'page with widgets') + ')' : ' (' + t('tables', 'view') + ')'),
			}))
			const tables = this.tables.map(table => ({
				id: 'table-' + table.id,
				targetType: 'table',
				targetId: table.id,
				label: (table.emoji ? table.emoji + ' ' : '') + table.title + ' (' + t('tables', 'table') + ')',
			}))
			return [...views, ...tables, { id: URL_OPTION_ID, targetType: 'url', targetId: null, label: t('tables', 'External link') }]
		},
	},
	methods: {
		targetOption(item) {
			if (item.targetType === 'url') {
				return this.targetOptions.find(option => option.id === URL_OPTION_ID)
			}
			return this.targetOptions.find(option => option.targetType === item.targetType && option.targetId === item.targetId) ?? null
		},
		emitItems(items) {
			this.$emit('update:items', items)
		},
		update(index, changes) {
			this.emitItems(this.items.map((item, i) => i === index ? { ...item, ...changes } : item))
		},
		setTarget(index, option) {
			if (!option) {
				return
			}
			const changes = { targetType: option.targetType, targetId: option.targetId }
			if (option.targetType !== 'url' && this.items[index].label === '') {
				changes.label = option.label.replace(/\s\([^)]*\)$/, '')
			}
			this.update(index, changes)
		},
		add() {
			this.emitItems([...this.items, {
				key: 'new-' + Date.now(),
				label: '',
				icon: null,
				targetType: 'url',
				targetId: null,
				url: '',
				technicalName: null,
				parentKey: null,
			}])
		},
		remove(index) {
			const removed = this.items[index]
			// children of a removed parent move to the top level instead of disappearing
			this.emitItems(this.items
				.filter((item, i) => i !== index)
				.map(item => item.parentKey === removed.key ? { ...item, parentKey: null } : item))
		},
		/**
		 * The nearest top-level item above, which is the only possible parent.
		 * @param index
		 */
		parentAbove(index) {
			for (let i = index - 1; i >= 0; i--) {
				if (this.items[i].parentKey === null) {
					return this.items[i]
				}
			}
			return null
		},
		hasChildren(item) {
			return this.items.some(candidate => candidate.parentKey === item.key)
		},
		canIndent(index) {
			return this.parentAbove(index) !== null && !this.hasChildren(this.items[index])
		},
		indent(index) {
			const parent = this.parentAbove(index)
			if (!parent || this.hasChildren(this.items[index])) {
				return
			}
			this.update(index, { parentKey: parent.key })
		},
		outdent(index) {
			// a child that leaves its parent lands after the parent's last child, as a top-level entry
			const items = [...this.items]
			const [moved] = items.splice(index, 1)
			let insertAt = items.findIndex(item => item.key === moved.parentKey) + 1
			while (insertAt < items.length && items[insertAt].parentKey === moved.parentKey) {
				insertAt++
			}
			items.splice(insertAt, 0, { ...moved, parentKey: null })
			this.emitItems(items)
		},
		/**
		 * An item with its children, as one block that moves together.
		 * @param index
		 */
		block(index) {
			const item = this.items[index]
			if (item.parentKey !== null) {
				return [item]
			}
			return [item, ...this.items.filter(candidate => candidate.parentKey === item.key)]
		},
		dragStart(index, event) {
			this.draggedIndex = index
			if (event?.dataTransfer) {
				event.dataTransfer.effectAllowed = 'move'
			}
		},
		dragOver(index) {
			if (this.draggedIndex === null || this.draggedIndex === index) {
				return
			}
			const moving = this.block(this.draggedIndex)
			const over = this.items[index]
			if (moving.includes(over)) {
				return
			}
			const rest = this.items.filter(item => !moving.includes(item))
			let insertAt = rest.indexOf(over)
			if (index > this.draggedIndex) {
				insertAt += 1
			}
			// a child stays a child of the parent whose block it lands in, a parent block stays top-level
			const head = moving[0]
			const landingParent = this.parentOfPosition(rest, insertAt)
			const placed = head.parentKey !== null
				? [{ ...head, parentKey: landingParent?.key ?? null }]
				: moving
			if (head.parentKey === null && landingParent !== null) {
				// a top-level block cannot land inside another parent's children: move it behind them
				while (insertAt < rest.length && rest[insertAt].parentKey !== null) {
					insertAt++
				}
			}
			rest.splice(insertAt, 0, ...placed)
			this.draggedIndex = rest.indexOf(placed[0])
			this.emitItems(rest)
		},
		/**
		 * The parent an item inserted at this position would belong to, or null at the top level.
		 * @param items
		 * @param position
		 */
		parentOfPosition(items, position) {
			const previous = items[position - 1]
			if (!previous) {
				return null
			}
			if (previous.parentKey !== null) {
				return items.find(item => item.key === previous.parentKey) ?? null
			}
			const next = items[position]
			return next && next.parentKey === previous.key ? previous : null
		},
		dragEnd() {
			this.draggedIndex = null
		},
	},
}
</script>

<style lang="scss" scoped>
.menu-items-editor {
	// the dialog's labels float left, so the editor must start on its own line
	clear: both;
	width: 100%;
	min-width: 0;

	&__help {
		color: var(--color-text-maxcontrast);
		margin: 0 0 calc(2 * var(--default-grid-baseline, 4px));
	}

	&__list {
		margin-bottom: calc(2 * var(--default-grid-baseline, 4px));
	}

	&__item {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-end;
		gap: calc(2 * var(--default-grid-baseline, 4px));
		padding: calc(2 * var(--default-grid-baseline, 4px)) 0;
		border-bottom: 1px solid var(--color-border);
		list-style: none;

		:deep(.input-field) {
			flex: 1 1 160px;
			min-width: 0;
		}

		&--child {
			padding-inline-start: calc(9 * var(--default-grid-baseline, 4px));
			border-inline-start: 2px solid var(--color-primary-element-light);
		}

		&--dragging {
			opacity: 0.5;
		}
	}

	&__handle {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: var(--default-clickable-area, 44px);
		height: var(--default-clickable-area, 44px);
		color: var(--color-text-maxcontrast);
		cursor: grab;
	}

	&__target {
		flex: 1 1 200px;
		min-width: 0;
	}

	&__row-actions {
		display: flex;
		gap: calc(1 * var(--default-grid-baseline, 4px));
	}

	&__empty {
		color: var(--color-text-maxcontrast);
		margin-bottom: calc(2 * var(--default-grid-baseline, 4px));
	}
}
</style>
