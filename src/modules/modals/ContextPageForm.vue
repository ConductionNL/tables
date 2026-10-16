<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog v-if="showModal"
		:name="page ? t('tables', 'Edit page details') : t('tables', 'Add page')"
		size="normal"
		data-cy="contextPageForm"
		@closing="$emit('close')">
		<form class="context-page-form" @submit.prevent="submit">
			<div class="context-page-form__title">
				<NcEmojiPicker :close-on-select="true" @select="value => emoji = value">
					<NcButton variant="tertiary"
						:aria-label="t('tables', 'Pick an emoji for the page')"
						data-cy="contextPageEmoji"
						@click.prevent>
						<template v-if="!emoji" #icon>
							<EmoticonOutline :size="20" />
						</template>
						{{ emoji }}
					</NcButton>
				</NcEmojiPicker>
				<NcTextField v-model="title"
					class="context-page-form__title-input"
					:label="t('tables', 'Title')"
					:maxlength="200"
					data-cy="contextPageTitle" />
			</div>
			<NcButton v-if="emoji" variant="tertiary" data-cy="contextPageRemoveEmoji" @click.prevent="emoji = ''">
				{{ t('tables', 'Remove emoji') }}
			</NcButton>
			<NcTextField v-model="technicalName"
				:label="t('tables', 'Technical name')"
				:helper-text="technicalNameHelperText"
				:error="!technicalNameIsValid"
				:maxlength="200"
				data-cy="contextPageTechnicalName" />
			<NcTextArea v-model="description"
				:label="t('tables', 'Description')"
				:maxlength="2000"
				resize="vertical"
				rows="3"
				data-cy="contextPageDescription" />
		</form>
		<template #actions>
			<NcButton variant="primary" :disabled="!canSubmit" data-cy="contextPageSubmit" @click="submit">
				{{ page ? t('tables', 'Save') : t('tables', 'Add page') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcEmojiPicker, NcTextArea, NcTextField } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import EmoticonOutline from 'vue-material-design-icons/EmoticonOutline.vue'

const TECHNICAL_NAME = /^[a-z][a-z0-9_]*$/

/**
 * Asks for the title, emoji, technical name and description of a new grid page, or of
 * the page being edited.
 */
export default {
	name: 'ContextPageForm',
	components: {
		NcButton,
		NcDialog,
		NcEmojiPicker,
		NcTextArea,
		NcTextField,
		EmoticonOutline,
	},
	props: {
		showModal: {
			type: Boolean,
			default: false,
		},
		// The page to edit; null for a new page
		page: {
			type: Object,
			default: null,
		},
	},
	emits: ['close', 'submit'],
	data() {
		return {
			title: '',
			emoji: '',
			technicalName: '',
			description: '',
		}
	},
	computed: {
		// A new page may leave it empty, the server then makes one from the title
		technicalNameIsValid() {
			return (this.technicalName === '' && !this.page) || TECHNICAL_NAME.test(this.technicalName)
		},
		technicalNameHelperText() {
			if (!this.technicalNameIsValid) {
				return this.technicalName === ''
					? t('tables', 'Every page needs a technical name.')
					: t('tables', 'Start with a lowercase letter and use only lowercase letters, digits and underscores.')
			}
			return this.page
				? t('tables', 'Lowercase letters, digits and underscores, starting with a letter.')
				: t('tables', 'Leave empty to make one from the title.')
		},
		canSubmit() {
			return this.title.trim() !== '' && this.technicalNameIsValid
		},
	},
	watch: {
		showModal(open) {
			if (open) {
				this.title = this.page?.title ?? ''
				this.emoji = this.page?.emoji ?? ''
				this.technicalName = this.page?.technicalName ?? ''
				this.description = this.page?.description ?? ''
			}
		},
	},
	methods: {
		submit() {
			if (!this.canSubmit) {
				return
			}
			const data = { title: this.title.trim(), emoji: this.emoji, description: this.description.trim() }
			if (this.technicalName !== '') {
				data.technicalName = this.technicalName
			}
			this.$emit('submit', data)
		},
	},
}
</script>

<style lang="scss" scoped>
.context-page-form {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));

	&__title {
		display: flex;
		align-items: flex-end;
		gap: calc(2 * var(--default-grid-baseline));
	}

	&__title-input {
		flex: 1;
	}
}
</style>
