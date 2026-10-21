<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection :name="t('tables', 'Formats')"
		:description="t('tables', 'Text and number columns can use a format as their subtype. Their values are checked against it in the browser and on the server.')">
		<NcButton data-cy="addFormat" @click="editedFormat = {}">
			<template #icon>
				<PlusCircleOutlineIcon :size="20" />
			</template>
			{{ t('tables', 'Add format') }}
		</NcButton>

		<NcLoadingIcon v-if="loading" />
		<table v-else class="formats">
			<thead>
				<tr>
					<th>{{ t('tables', 'Format') }}</th>
					<th>{{ t('tables', 'Technical name') }}</th>
					<th>{{ t('tables', 'Type') }}</th>
					<th>{{ t('tables', 'Source') }}</th>
					<th>{{ t('tables', 'Version') }}</th>
					<th>{{ t('tables', 'Enabled') }}</th>
					<th><span class="hidden-visually">{{ t('tables', 'Actions') }}</span></th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="format in formats" :key="format.id" :data-cy="'format-' + format.technicalName">
					<td>{{ format.displayLabel }}</td>
					<td><code>{{ format.technicalName }}</code></td>
					<td>{{ format.type === 'number' ? t('tables', 'Number') : t('tables', 'Text') }}</td>
					<td>{{ sourceLabel(format.source) }}</td>
					<td>{{ format.version }}</td>
					<td>
						<NcCheckboxRadioSwitch type="switch"
							:model-value="format.enabled"
							:aria-label="t('tables', 'Enable {format}', { format: format.displayLabel })"
							@update:model-value="setEnabled(format, $event)" />
					</td>
					<td class="actions">
						<template v-if="format.source !== 'core'">
							<NcButton variant="tertiary" :aria-label="t('tables', 'Edit {format}', { format: format.displayLabel })" @click="editedFormat = format">
								<template #icon>
									<PencilOutlineIcon :size="20" />
								</template>
							</NcButton>
							<NcButton variant="tertiary" :aria-label="t('tables', 'Delete {format}', { format: format.displayLabel })" @click="askToDelete(format)">
								<template #icon>
									<TrashCanOutlineIcon :size="20" />
								</template>
							</NcButton>
						</template>
					</td>
				</tr>
			</tbody>
		</table>

		<FormatEditor v-if="editedFormat"
			:format="editedFormat.id ? editedFormat : null"
			@saved="onSaved"
			@close="editedFormat = null" />

		<NcDialog v-if="deletion"
			:name="t('tables', 'Delete format')"
			:buttons="deletionButtons"
			@closing="deletion = null">
			<p>{{ deletion.message }}</p>
		</NcDialog>
	</NcSettingsSection>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon, NcSettingsSection } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import PlusCircleOutlineIcon from 'vue-material-design-icons/PlusCircleOutline.vue'
import PencilOutlineIcon from 'vue-material-design-icons/PencilOutline.vue'
import TrashCanOutlineIcon from 'vue-material-design-icons/TrashCanOutline.vue'
import FormatEditor from './FormatEditor.vue'
import { conflictOf, deleteFormat, errorMessage, fetchFormats, updateFormat } from './formatsApi.js'

export default {
	name: 'FormatSettings',
	components: {
		FormatEditor,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcSettingsSection,
		PencilOutlineIcon,
		PlusCircleOutlineIcon,
		TrashCanOutlineIcon,
	},

	data() {
		return {
			formats: [],
			loading: true,
			editedFormat: null,
			// the format to delete, with the message to confirm and how many columns will break
			deletion: null,
		}
	},

	computed: {
		deletionButtons() {
			return [
				{ label: t('tables', 'Cancel'), callback: () => { this.deletion = null } },
				// the dialog stays open until the server answers, which may ask for a second confirmation
				{ label: t('tables', 'Delete'), variant: 'error', callback: () => { this.confirmDeletion(); return false } },
			]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		async load() {
			try {
				this.formats = await fetchFormats()
			} catch (error) {
				showError(errorMessage(error, t('tables', 'Could not load the formats.')))
			} finally {
				this.loading = false
			}
		},

		sourceLabel(source) {
			if (source === 'core') {
				return t('tables', 'Tables')
			}
			if (source.startsWith('pack:')) {
				return t('tables', 'Pack {pack}', { pack: source.slice(5) })
			}
			return t('tables', 'Custom')
		},

		async setEnabled(format, enabled) {
			try {
				Object.assign(format, await updateFormat(format.id, { enabled }))
			} catch (error) {
				showError(errorMessage(error, t('tables', 'Could not change the format.')))
			}
		},

		onSaved() {
			this.editedFormat = null
			this.load()
		},

		askToDelete(format) {
			this.deletion = {
				format,
				brokenColumnsCount: null,
				message: t('tables', 'Delete the format {format}?', { format: format.displayLabel }),
			}
		},

		/**
		 * The server refuses to delete a format in use until the number of columns that break is
		 * confirmed, so the first attempt may only tell that number.
		 */
		async confirmDeletion() {
			const { format, brokenColumnsCount } = this.deletion
			try {
				await deleteFormat(format.id, brokenColumnsCount)
				showSuccess(t('tables', 'The format {format} was deleted.', { format: format.displayLabel }))
				this.deletion = null
				this.load()
			} catch (error) {
				const conflict = conflictOf(error)
				if (conflict === null) {
					showError(errorMessage(error, t('tables', 'Could not delete the format.')))
					this.deletion = null
					return
				}
				this.deletion = {
					format,
					brokenColumnsCount: conflict.brokenColumnsCount,
					message: n('tables', '%n column uses this format. It keeps its values, but they are no longer checked and the column is marked as broken. Delete anyway?', '%n columns use this format. They keep their values, but they are no longer checked and the columns are marked as broken. Delete anyway?', conflict.brokenColumnsCount),
				}
			}
		},
	},
}
</script>

<style scoped>
.formats {
	margin-top: calc(var(--default-grid-baseline) * 4);
	width: 100%;
	border-collapse: collapse;

	th,
	td {
		padding: calc(var(--default-grid-baseline) * 2);
		text-align: start;
		border-bottom: 1px solid var(--color-border);
	}

	.actions {
		display: flex;
		justify-content: flex-end;
	}
}
</style>
