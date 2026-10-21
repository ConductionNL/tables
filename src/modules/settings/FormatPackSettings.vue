<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection :name="t('tables', 'Format packs')"
		:description="t('tables', 'Packs add formats that are common in a country. Columns can use them once the pack is imported.')">
		<NcLoadingIcon v-if="loading" />
		<ul v-else class="packs">
			<li v-for="pack in packs" :key="pack.name" class="packs__pack" :data-cy="'pack-' + pack.name">
				<PackageVariantClosedIcon :size="20" />
				<div class="packs__description">
					<strong>{{ pack.label }}</strong>
					<span>{{ t('tables', '{installed} of {total} formats installed', { installed: pack.installedCount, total: pack.formatsCount }) }}</span>
					<span v-if="pack.updateAvailable" class="packs__update" data-cy="packUpdateAvailable">{{ t('tables', 'Update available') }}</span>
				</div>
				<NcButton :disabled="busy" @click="startImport(pack)">
					{{ pack.installedCount === 0 ? t('tables', 'Import') : t('tables', 'Update') }}
				</NcButton>
				<NcButton v-if="pack.installedCount > 0" :disabled="busy" variant="tertiary" @click="askToRemove(pack)">
					{{ t('tables', 'Remove') }}
				</NcButton>
			</li>
		</ul>

		<NcDialog v-if="conflicts"
			:name="t('tables', 'Import {pack}', { pack: conflicts.pack.label })"
			size="normal"
			:buttons="importButtons"
			@closing="conflicts = null">
			<p>{{ t('tables', 'These formats already exist with another definition. Choose for each whether to keep the installed one or to replace it with the one from the pack.') }}</p>
			<ul class="conflicts">
				<li v-for="item in conflicts.items" :key="item.technicalName" :data-cy="'conflict-' + item.technicalName">
					<strong>{{ item.label }}</strong> <code>{{ item.technicalName }}</code>
					<span>{{ conflictDescription(item) }}</span>
					<span v-if="item.columnsCount > 0">{{ impactDescription(item) }}</span>
					<div class="conflicts__choice">
						<NcCheckboxRadioSwitch v-model="conflicts.resolution[item.technicalName]" value="keep" type="radio" :name="'resolution-' + item.technicalName">
							{{ t('tables', 'Keep the installed format') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="conflicts.resolution[item.technicalName]" value="replace" type="radio" :name="'resolution-' + item.technicalName">
							{{ t('tables', 'Replace it with the pack format') }}
						</NcCheckboxRadioSwitch>
					</div>
				</li>
			</ul>
			<p v-if="conflicts.coreCount > 0">
				{{ n('tables', '%n format of the pack has the name of a format Tables ships, which is kept.', '%n formats of the pack have the name of a format Tables ships, which are kept.', conflicts.coreCount) }}
			</p>
		</NcDialog>

		<NcDialog v-if="removal"
			:name="t('tables', 'Remove {pack}', { pack: removal.pack.label })"
			:buttons="removalButtons"
			@closing="removal = null">
			<p>{{ removal.message }}</p>
		</NcDialog>
	</NcSettingsSection>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon, NcSettingsSection } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import PackageVariantClosedIcon from 'vue-material-design-icons/PackageVariantClosed.vue'
import { conflictOf, errorMessage, fetchPacks, importPack, previewPack, removePack } from './formatsApi.js'

const NEEDS_CHOICE = ['update', 'conflict']

export default {
	name: 'FormatPackSettings',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcSettingsSection,
		PackageVariantClosedIcon,
	},

	emits: ['changed'],

	data() {
		return {
			packs: [],
			loading: true,
			busy: false,
			// the pack being imported, the formats that need a choice and the choices made
			conflicts: null,
			// the pack being removed, with the message to confirm and how many columns will break
			removal: null,
		}
	},

	computed: {
		importButtons() {
			return [
				{ label: t('tables', 'Cancel'), callback: () => { this.conflicts = null } },
				{ label: t('tables', 'Import'), variant: 'primary', callback: () => { this.runImport(this.conflicts.pack, this.conflicts.resolution); return false } },
			]
		},

		removalButtons() {
			return [
				{ label: t('tables', 'Cancel'), callback: () => { this.removal = null } },
				{ label: t('tables', 'Remove'), variant: 'error', callback: () => { this.confirmRemoval(); return false } },
			]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,
		async load() {
			try {
				this.packs = await fetchPacks()
			} catch (error) {
				showError(errorMessage(error, t('tables', 'Could not load the format packs.')))
			} finally {
				this.loading = false
			}
		},

		/**
		 * A pack without conflicts imports with one click; otherwise every format that would change
		 * gets a choice, with replacing as the default for a newer version from the same pack.
		 *
		 * @param {object} pack the pack to import
		 */
		async startImport(pack) {
			this.busy = true
			try {
				const items = await previewPack(pack.name)
				const needsChoice = items.filter(item => NEEDS_CHOICE.includes(item.status))
				if (needsChoice.length === 0) {
					await this.runImport(pack, {})
					return
				}
				this.conflicts = {
					pack,
					items: needsChoice,
					coreCount: items.filter(item => item.status === 'core').length,
					resolution: Object.fromEntries(needsChoice.map(item => [item.technicalName, item.status === 'update' ? 'replace' : 'keep'])),
				}
			} catch (error) {
				showError(errorMessage(error, t('tables', 'Could not read the format pack.')))
			} finally {
				this.busy = false
			}
		},

		async runImport(pack, resolution) {
			this.busy = true
			try {
				const result = await importPack(pack.name, resolution)
				showSuccess(n('tables', '{pack}: %n format created.', '{pack}: %n formats created.', result.created.length, { pack: pack.label })
					+ (result.replaced.length > 0 ? ' ' + n('tables', '%n replaced.', '%n replaced.', result.replaced.length) : ''))
				this.conflicts = null
				await this.load()
				this.$emit('changed')
			} catch (error) {
				showError(errorMessage(error, t('tables', 'Could not import the format pack.')))
			} finally {
				this.busy = false
			}
		},

		conflictDescription(item) {
			if (item.status === 'update') {
				return t('tables', 'Update from version {installed} to {version}.', { installed: item.installedVersion, version: item.version })
			}
			return t('tables', 'Installed version {installed} differs from the pack version {version}.', { installed: item.installedVersion, version: item.version })
		},

		impactDescription(item) {
			return n('tables', 'Replacing it affects %n column', 'Replacing it affects %n columns', item.columnsCount)
				+ '; ' + n('tables', '%n stored value would not satisfy the new rule.', '%n stored values would not satisfy the new rule.', item.failingValuesCount)
		},

		askToRemove(pack) {
			this.removal = {
				pack,
				brokenColumnsCount: null,
				message: t('tables', 'Remove the formats of {pack}?', { pack: pack.label }),
			}
		},

		async confirmRemoval() {
			const { pack, brokenColumnsCount } = this.removal
			try {
				await removePack(pack.name, brokenColumnsCount)
				showSuccess(t('tables', 'The formats of {pack} were removed.', { pack: pack.label }))
				this.removal = null
				await this.load()
				this.$emit('changed')
			} catch (error) {
				const conflict = conflictOf(error)
				if (conflict === null) {
					showError(errorMessage(error, t('tables', 'Could not remove the format pack.')))
					this.removal = null
					return
				}
				this.removal = {
					pack,
					brokenColumnsCount: conflict.brokenColumnsCount,
					message: n('tables', '%n column uses formats of this pack. It keeps its values, but they are no longer checked and the column is marked as broken. Remove anyway?', '%n columns use formats of this pack. They keep their values, but they are no longer checked and the columns are marked as broken. Remove anyway?', conflict.brokenColumnsCount),
				}
			}
		},
	},
}
</script>

<style scoped>
.packs {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);

	&__pack {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__description {
		display: flex;
		flex-direction: column;
		flex-grow: 1;
	}

	&__update {
		color: var(--color-warning-text);
	}
}

.conflicts {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
	margin-block: calc(var(--default-grid-baseline) * 3);

	li {
		display: flex;
		flex-direction: column;
	}

	&__choice {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 4);
	}
}
</style>
