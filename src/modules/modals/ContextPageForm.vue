<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog v-if="showModal"
		:name="page ? t('tables', 'Rename page') : t('tables', 'New page')"
		size="small"
		data-cy="contextPageForm"
		@closing="$emit('close')">
		<form class="context-page-form" @submit.prevent="submit">
			<NcTextField v-model="title"
				:label="t('tables', 'Title')"
				:maxlength="200"
				data-cy="contextPageTitle" />
			<NcTextField v-model="technicalName"
				:label="t('tables', 'Technical name')"
				:helper-text="technicalNameIsValid ? t('tables', 'Optional. Lowercase letters, digits and underscores, starting with a letter.') : t('tables', 'Start with a lowercase letter and use only lowercase letters, digits and underscores.')"
				:error="!technicalNameIsValid"
				:maxlength="200"
				data-cy="contextPageTechnicalName" />
		</form>
		<template #actions>
			<NcButton variant="primary" :disabled="!canSubmit" data-cy="contextPageSubmit" @click="submit">
				{{ page ? t('tables', 'Save') : t('tables', 'Create page') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'

const TECHNICAL_NAME = /^[a-z][a-z0-9_]*$/

/**
 * Asks for the title and technical name of a new page, or of the page being renamed.
 */
export default {
	name: 'ContextPageForm',
	components: {
		NcButton,
		NcDialog,
		NcTextField,
	},
	props: {
		showModal: {
			type: Boolean,
			default: false,
		},
		// The page to rename; null for a new page
		page: {
			type: Object,
			default: null,
		},
	},
	emits: ['close', 'submit'],
	data() {
		return {
			title: '',
			technicalName: '',
		}
	},
	computed: {
		technicalNameIsValid() {
			return this.technicalName === '' || TECHNICAL_NAME.test(this.technicalName)
		},
		canSubmit() {
			return this.title.trim() !== '' && this.technicalNameIsValid
		},
	},
	watch: {
		showModal(open) {
			if (open) {
				this.title = this.page?.title ?? ''
				this.technicalName = this.page?.technical_name ?? ''
			}
		},
	},
	methods: {
		submit() {
			if (!this.canSubmit) {
				return
			}
			this.$emit('submit', { title: this.title.trim(), technicalName: this.technicalName })
		},
	},
}
</script>

<style lang="scss" scoped>
.context-page-form {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}
</style>
