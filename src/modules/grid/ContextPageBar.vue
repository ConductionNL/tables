<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<nav class="context-page-bar" :aria-label="t('tables', 'Pages of this application')" data-cy="contextPageBar">
		<ul class="context-page-bar__list">
			<li>
				<RouterLink class="context-page-bar__link"
					:class="{ 'context-page-bar__link--active': activePageId === null }"
					:aria-current="activePageId === null ? 'page' : undefined"
					:to="{ name: 'context', params: { contextId: context.id } }"
					data-cy="contextStartPageLink">
					{{ t('tables', 'Start page') }}
				</RouterLink>
			</li>
			<li v-for="page in gridPages" :key="page.id">
				<RouterLink class="context-page-bar__link"
					:class="{ 'context-page-bar__link--active': activePageId === page.id }"
					:aria-current="activePageId === page.id ? 'page' : undefined"
					:to="{ name: 'contextPage', params: { contextId: context.id, pageId: page.id } }"
					data-cy="contextPageLink">
					<span v-if="page.emoji" aria-hidden="true">{{ page.emoji }}</span>
					{{ page.title }}
				</RouterLink>
			</li>
			<li v-if="canManage">
				<NcButton variant="tertiary" data-cy="contextPageAdd" @click="$emit('add-page')">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('tables', 'Add page') }}
				</NcButton>
			</li>
		</ul>
	</nav>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { PAGE_TYPE_GRID } from './gridLayout.js'

/**
 * The pages of an application under its description: the start page, one tab per grid
 * page, and adding a page for those who manage the application.
 */
export default {
	name: 'ContextPageBar',
	components: {
		NcButton,
		Plus,
	},
	props: {
		context: {
			type: Object,
			required: true,
		},
		// The grid page that is shown; null on the start page
		activePageId: {
			type: Number,
			default: null,
		},
		canManage: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['add-page'],
	computed: {
		gridPages() {
			return Object.values(this.context.pages ?? {})
				.filter(page => page.page_type === PAGE_TYPE_GRID)
				.sort((a, b) => a.id - b.id)
		},
	},
}
</script>

<style lang="scss" scoped>
.context-page-bar {
	margin-inline-start: 32px;
	border-bottom: 1px solid var(--color-border);

	&__list {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: var(--default-grid-baseline);
	}

	&__link {
		display: inline-flex;
		align-items: center;
		gap: var(--default-grid-baseline);
		min-height: var(--default-clickable-area);
		padding: 0 calc(3 * var(--default-grid-baseline));
		border-bottom: 2px solid transparent;
		color: var(--color-text-maxcontrast);

		&:hover,
		&:focus-visible {
			color: var(--color-main-text);
		}

		&--active {
			border-bottom-color: var(--color-primary-element);
			color: var(--color-main-text);
			font-weight: bold;
		}
	}
}
</style>
