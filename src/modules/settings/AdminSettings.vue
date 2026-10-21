<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<FormatSettings ref="formats" />
		<FormatPackSettings @changed="$refs.formats.load()" />
	</div>
</template>

<script>
import FormatSettings from './FormatSettings.vue'
import FormatPackSettings from './FormatPackSettings.vue'

export default {
	name: 'AdminSettings',
	components: {
		FormatSettings,
		FormatPackSettings,
	},
}
</script>
