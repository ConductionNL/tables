/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const formatsUrl = (path = '') => generateOcsUrl('/apps/tables/api/2/formats' + path)

/**
 * The message an API error carries, or the fallback
 *
 * @param {Error} error the axios error
 * @param {string} fallback shown when the response has no message
 * @return {string}
 */
export function errorMessage(error, fallback) {
	return error?.response?.data?.ocs?.data?.message || fallback
}

/**
 * The body of a 409 response, which asks for a confirmation or a choice, or null
 *
 * @param {Error} error the axios error
 * @return {object|null}
 */
export function conflictOf(error) {
	return error?.response?.status === 409 ? error.response.data.ocs.data : null
}

export async function fetchFormats() {
	return (await axios.get(formatsUrl())).data.ocs.data
}

export async function createFormat(definition) {
	return (await axios.post(formatsUrl(), definition)).data.ocs.data
}

export async function updateFormat(id, changes) {
	return (await axios.put(formatsUrl('/' + id), changes)).data.ocs.data
}

export async function previewFormatUpdate(id, changes) {
	return (await axios.post(formatsUrl('/' + id + '/preview'), changes)).data.ocs.data
}

export async function deleteFormat(id, confirmBrokenColumns = null) {
	const params = confirmBrokenColumns === null ? {} : { confirmBrokenColumns }
	return (await axios.delete(formatsUrl('/' + id), { params })).data.ocs.data
}

export async function fetchPacks() {
	return (await axios.get(formatsUrl('/packs'))).data.ocs.data
}

export async function previewPack(name) {
	return (await axios.get(formatsUrl('/packs/' + name + '/preview'))).data.ocs.data
}

/**
 * @param {string} name the pack
 * @param {object} resolution keep or replace by technical name, sent as resolution[{technicalName}] query parameters
 * @return {Promise<object>}
 */
export async function importPack(name, resolution = {}) {
	return (await axios.post(formatsUrl('/packs/' + name + '/import'), null, { params: { resolution } })).data.ocs.data
}

export async function removePack(name, confirmBrokenColumns = null) {
	const params = confirmBrokenColumns === null ? {} : { confirmBrokenColumns }
	return (await axios.delete(formatsUrl('/packs/' + name), { params })).data.ocs.data
}
