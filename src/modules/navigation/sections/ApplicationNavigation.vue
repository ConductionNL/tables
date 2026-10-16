<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppNavigation :aria-label="context ? context.name : t('tables', 'Application')">
		<template #list>
			<NcLoadingIcon v-if="!context" :size="32" />
			<template v-else>
				<div class="application-navigation__header" data-cy="application-nav-header">
					<ApplicationIcon :icon-name="context.iconName" :size="32" />
					<div class="application-navigation__title">
						<strong>{{ context.name }}</strong>
						<span v-if="context.description" class="application-navigation__description">{{ context.description }}</span>
					</div>
				</div>
				<NcAppNavigationItem v-for="branch in menuTree"
					:key="branch.item.id"
					:name="branch.item.label"
					:to="branch.item.targetType === 'url' ? undefined : menuItemRoute(branch.item, context.id)"
					:href="branch.item.targetType === 'url' ? branch.item.url : undefined"
					:class="{ active: isActive(branch.item) }"
					:allow-collapse="branch.children.length > 0"
					:open="branch.children.some(child => isActive(child))"
					data-cy="application-nav-item">
					<template #icon>
						<MenuItemIcon :item="branch.item" />
					</template>
					<template v-if="branch.children.length > 0">
						<NcAppNavigationItem v-for="child in branch.children"
							:key="child.id"
							:name="child.label"
							:to="child.targetType === 'url' ? undefined : menuItemRoute(child, context.id)"
							:href="child.targetType === 'url' ? child.url : undefined"
							:class="{ active: isActive(child) }"
							data-cy="application-nav-item">
							<template #icon>
								<MenuItemIcon :item="child" />
							</template>
						</NcAppNavigationItem>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem v-if="startPage && !startPageInMenu"
					:name="startPage.title || t('tables', 'Dashboard')"
					:to="'/application/' + context.id"
					:class="{ active: !$route.params.itemName && !$route.params.pageId }"
					data-cy="application-nav-overview">
					<template #icon>
						<ViewGridOutline :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
		</template>
		<template #footer>
			<div v-if="context && ownsContext(context)" class="application-navigation__footer">
				<NcButton variant="tertiary" :href="configurationUrl" data-cy="application-nav-configure">
					<template #icon>
						<CogOutline :size="20" />
					</template>
					{{ t('tables', 'Configure in Tables') }}
				</NcButton>
			</div>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import ViewGridOutline from 'vue-material-design-icons/ViewGridOutline.vue'
import { generateUrl } from '@nextcloud/router'
import { mapState } from 'pinia'
import ApplicationIcon from '../../../shared/components/ApplicationIcon.vue'
import MenuItemIcon from '../../../shared/components/MenuItemIcon.vue'
import permissionsMixin from '../../../shared/components/ncTable/mixins/permissionsMixin.js'
import { menuItemRoute, menuTree } from '../../../shared/utils/menuItems.js'
import { useTablesStore } from '../../../store/store.js'
import { PAGE_TYPE_START } from '../../grid/gridLayout.js'

/**
 * The navigation of an application opened on its own: its menu items are the entries.
 */
export default {
	name: 'ApplicationNavigation',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcButton,
		NcLoadingIcon,
		CogOutline,
		ViewGridOutline,
		ApplicationIcon,
		MenuItemIcon,
	},
	mixins: [permissionsMixin],
	computed: {
		...mapState(useTablesStore, ['activeContext']),
		context() {
			return this.activeContext
		},
		menuItems() {
			return [...(this.context?.menuItems ?? [])].sort((a, b) => a.order - b.order)
		},
		menuTree() {
			return menuTree(this.menuItems)
		},
		startPage() {
			return Object.values(this.context?.pages ?? {}).find(page => page.page_type === PAGE_TYPE_START) ?? null
		},
		/**
		 * Whether a menu item opens the start page. When none does, the start page gets an entry of
		 * its own, so it stays reachable.
		 *
		 * @return {boolean}
		 */
		startPageInMenu() {
			return this.menuItems.some(item => item.targetType === 'page' && item.targetId === this.startPage?.id)
		},
		configurationUrl() {
			return generateUrl('/apps/tables/') + '#/application/' + this.context.id
		},
	},
	methods: {
		menuItemRoute,
		isActive(item) {
			return item.targetType !== 'url' && this.$route.params.itemName === item.technicalName
		},
	},
}
</script>

<style lang="scss" scoped>
.application-navigation {
	&__header {
		display: flex;
		align-items: center;
		gap: calc(3 * var(--default-grid-baseline));
		padding: calc(3 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
	}

	&__title {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__description {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__footer {
		padding: calc(2 * var(--default-grid-baseline));
	}
}
</style>
