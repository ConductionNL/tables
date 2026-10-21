/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import AdminSettings from './modules/settings/AdminSettings.vue'

const app = createApp(AdminSettings)
app.mixin({ methods: { t, n } })
app.mount('#tables-admin-settings')
