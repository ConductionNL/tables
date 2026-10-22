<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<AlertCircleOutline v-if="item.broken" :size="size" :title="t('tables', 'The page, view or table this entry opened no longer exists')" />
	<ApplicationIcon v-else-if="item.icon" :icon-name="item.icon" :size="size" />
	<OpenInNew v-else-if="item.targetType === 'url'" :size="size" />
	<TableIcon v-else-if="item.targetType === 'table'" :size="size" />
	<ViewDashboardOutline v-else :size="size" />
</template>

<script>
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import TableIcon from 'vue-material-design-icons/Table.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import ApplicationIcon from './ApplicationIcon.vue'

/**
 * The icon of a menu entry: a warning when its target is gone, else the icon chosen for it,
 * else one for what it opens.
 */
export default {
	name: 'MenuItemIcon',
	components: {
		AlertCircleOutline,
		ApplicationIcon,
		OpenInNew,
		TableIcon,
		ViewDashboardOutline,
	},
	props: {
		item: {
			type: Object,
			required: true,
		},
		size: {
			type: Number,
			default: 20,
		},
	},
}
</script>
