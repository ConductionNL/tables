<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog :name="format ? t('tables', 'Edit format') : t('tables', 'Add format')"
		size="normal"
		:buttons="buttons"
		@closing="$emit('close')">
		<div class="format-editor">
			<NcTextField v-if="!format"
				v-model="technicalName"
				:label="t('tables', 'Technical name')"
				:helper-text="t('tables', 'Columns name the format by this, such as nl.postcode. It cannot be changed later.')"
				data-cy="formatTechnicalName" />
			<NcTextField v-model="label" :label="t('tables', 'Label')" data-cy="formatLabel" />

			<fieldset v-if="!format" class="format-editor__choice">
				<legend>{{ t('tables', 'Column type') }}</legend>
				<NcCheckboxRadioSwitch v-model="type" value="text" type="radio" name="formatType">
					{{ t('tables', 'Text') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="type" value="number" type="radio" name="formatType">
					{{ t('tables', 'Number') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<fieldset class="format-editor__choice">
				<legend>{{ t('tables', 'Rule') }}</legend>
				<NcCheckboxRadioSwitch v-model="mode" value="regex" type="radio" name="formatMode">
					{{ t('tables', 'Regular expression') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="mode" value="logic" type="radio" name="formatMode">
					{{ t('tables', 'JSON Logic') }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<NcTextField v-if="mode === 'regex'"
				v-model="pattern"
				:label="t('tables', 'Regular expression')"
				:error="patternError !== null"
				:helper-text="patternError ?? t('tables', 'Without delimiters, such as ^[0-9]{4} ?[A-Z]{2}$')"
				data-cy="formatPattern" />
			<NcTextArea v-else
				v-model="logic"
				:label="t('tables', 'JSON Logic rule')"
				:error="logicError !== null"
				:helper-text="logicError ?? t('tables', 'The rule reads the value as {\'var\': \'value\'}.')"
				resize="vertical"
				data-cy="formatLogic" />

			<NcTextField v-model="example"
				:label="t('tables', 'Example')"
				:error="exampleError !== null"
				:helper-text="exampleError ?? t('tables', 'Shown in validation errors, so it has to satisfy the rule.')"
				data-cy="formatExample" />
			<NcTextField v-model="version" :label="t('tables', 'Version')" />

			<NcNoteCard v-if="impact" type="warning" data-cy="formatImpact">
				{{ impactMessage }}
			</NcNoteCard>
		</div>
	</NcDialog>
</template>

<script>
import { NcCheckboxRadioSwitch, NcDialog, NcNoteCard, NcTextArea, NcTextField } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { evaluateFormatRule } from '../../shared/utils/formatRules.js'
import { createFormat, errorMessage, previewFormatUpdate, updateFormat } from './formatsApi.js'

/**
 * The pattern of a rule that only matches the value against one, which the regex mode edits
 *
 * @param {object|undefined} rule a JSON Logic rule
 * @return {string|null}
 */
function patternOf(rule) {
	const match = rule?.match
	return Array.isArray(match) && match.length === 2 && match[0]?.var === 'value' && typeof match[1] === 'string' && Object.keys(rule).length === 1 ? match[1] : null
}

export default {
	name: 'FormatEditor',
	components: {
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},

	props: {
		// the format to edit, or null to add one
		format: {
			type: Object,
			default: null,
		},
	},

	emits: ['saved', 'close'],

	data() {
		const pattern = patternOf(this.format?.rule)
		return {
			technicalName: this.format?.technicalName ?? '',
			label: this.format?.label ?? '',
			type: this.format?.type ?? 'text',
			mode: this.format && pattern === null ? 'logic' : 'regex',
			pattern: pattern ?? '',
			logic: this.format ? JSON.stringify(this.format.rule, null, 2) : '',
			example: this.format?.example ?? '',
			version: this.format?.version ?? '1.0.0',
			// what the change does to columns that use the format, shown before saving it
			impact: null,
			saving: false,
		}
	},

	computed: {
		patternError() {
			if (this.mode !== 'regex' || this.pattern === '') {
				return null
			}
			try {
				// the browser checks values with this, so the server only stores what compiles here as well
				RegExp(this.pattern, 'u')
				return null
			} catch (error) {
				return t('tables', 'Browsers cannot use this regular expression: {error}', { error: error.message })
			}
		},

		logicError() {
			if (this.mode !== 'logic') {
				return null
			}
			try {
				const rule = JSON.parse(this.logic)
				return rule !== null && typeof rule === 'object' && !Array.isArray(rule) ? null : t('tables', 'A rule is a JSON object with one operation.')
			} catch (error) {
				return t('tables', 'This is not valid JSON: {error}', { error: error.message })
			}
		},

		rule() {
			if (this.mode === 'regex') {
				return this.pattern === '' || this.patternError !== null ? null : { match: [{ var: 'value' }, this.pattern] }
			}
			return this.logicError === null ? JSON.parse(this.logic) : null
		},

		exampleError() {
			if (this.example === '' || this.rule === null) {
				return null
			}
			const value = this.type === 'number' && this.example.trim() !== '' && !isNaN(Number(this.example)) ? Number(this.example) : this.example
			try {
				return evaluateFormatRule(this.rule, value) ? null : t('tables', 'The example does not satisfy the rule.')
			} catch (error) {
				return t('tables', 'The rule cannot be used: {error}', { error: error.message })
			}
		},

		canSave() {
			return !this.saving && this.label.trim() !== '' && this.rule !== null && this.exampleError === null && (this.format !== null || this.technicalName !== '')
		},

		changes() {
			return { label: this.label, rule: this.rule, example: this.example, version: this.version }
		},

		impactMessage() {
			const columns = n('tables', '%n column uses this format.', '%n columns use this format.', this.impact.columnsCount)
			const values = n('tables', '%n stored value does not satisfy the changed rule; it stays readable, but has to match before it can be saved again.', '%n stored values do not satisfy the changed rule; they stay readable, but have to match before they can be saved again.', this.impact.failingValuesCount)
			return columns + ' ' + values
		},

		buttons() {
			return [
				{ label: t('tables', 'Cancel'), callback: () => this.$emit('close') },
				{
					label: this.impact ? t('tables', 'Save anyway') : t('tables', 'Save'),
					variant: 'primary',
					disabled: !this.canSave,
					// the dialog stays open until the server answers
					callback: () => { this.save(); return false },
				},
			]
		},
	},

	watch: {
		changes() {
			// a shown impact belongs to the definition it was computed for
			this.impact = null
		},
	},

	methods: {
		t,
		async save() {
			this.saving = true
			try {
				if (this.format === null) {
					await createFormat({ technicalName: this.technicalName, type: this.type, ...this.changes })
				} else if (this.impact === null && (await this.showImpactFirst())) {
					return
				} else {
					await updateFormat(this.format.id, this.changes)
				}
				this.$emit('saved')
			} catch (error) {
				showError(errorMessage(error, t('tables', 'Could not save the format.')))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Before a change to a format that columns use is saved, show what it does to them.
		 *
		 * @return {Promise<boolean>} whether the impact is shown and saving waits for a second click
		 */
		async showImpactFirst() {
			const impact = await previewFormatUpdate(this.format.id, this.changes)
			if (impact.columnsCount === 0) {
				return false
			}
			this.impact = impact
			return true
		},
	},
}
</script>

<style scoped>
.format-editor {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);

	&__choice {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 4);
		border: 0;
		padding: 0;

		legend {
			font-weight: bold;
		}
	}
}
</style>
