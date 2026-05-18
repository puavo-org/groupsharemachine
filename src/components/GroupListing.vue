<!--
SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="canShare && content.length > 0">
		<div class="widgetheaderdiv">
			<h2 class="widgetheader">
				{{ t('groupsharemachine', 'Share to a group') }}
			</h2>
			<NcTextField v-if="content.length > 3"
				style="width: auto;"
				v-model:value="filtertext"
				:label="t('groupsharemachine', 'Filter groups')"
				trailing-button-icon="close"
				:show-trailing-button="filtertext !== ''"
				@trailing-button-click="clearFilter" />
		</div>
		<div ref="newItem"
			class="grid"
			:title="t('groupsharemachine', 'Share to a group')">
			<NcButton v-for="item in sortedFilteredContent"
				:key="item.id"
				:aria-label="item.name"
				type="primary"
				@click="shareContent(item.id)">
				{{ item.name }}
			</NcButton>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, computed, onBeforeMount } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import axios from '@nextcloud/axios'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'

interface GroupInfo {
	id: string
	name: string
}

interface FileInfo {
	path: string
	name: string
	permissions: number
}

const props = defineProps<{
	fileInfo: FileInfo
}>()

const filtertext = ref('')
const content = ref<GroupInfo[]>([])

const getFullPath = computed(() => {
	if (props.fileInfo) {
		if (props.fileInfo.path.endsWith('/')) {
			return props.fileInfo.path + props.fileInfo.name
		}
		return props.fileInfo.path + '/' + props.fileInfo.name
	}
	return 'None'
})

const canShare = computed(() => {
	return !!(props.fileInfo.permissions & OC.PERMISSION_SHARE)
})

const filteredContent = computed(() => {
	if (filtertext.value.length === 0) {
		return content.value
	}
	return content.value.filter((p) => p.name.toLowerCase().includes(filtertext.value.toLowerCase()))
})

const sortedFilteredContent = computed(() => {
	return [...filteredContent.value].sort((a, b) =>
		a.name.toLowerCase().trim().localeCompare(b.name.toLowerCase().trim()),
	)
})

function clearFilter() {
	filtertext.value = ''
}

async function getContent() {
	const url = generateUrl('/apps/groupsharemachine/groups')
	try {
		const response = await axios.get<GroupInfo[]>(url)
		if (response.data.length > 0) {
			content.value = response.data
			console.debug('GroupShareMachine: loaded ' + response.data.length + ' groups')
		} else {
			console.debug('GroupShareMachine: no groups (not a teacher or no class groups)')
		}
	} catch (error) {
		console.debug(error)
	}
}

async function shareContent(targetGroupId: string) {
	const values = {
		path: getFullPath.value,
		shareType: 1,
		permissions: 1,
		shareWith: targetGroupId,
	}
	const url = generateOcsUrl('apps/files_sharing/api/v1/shares')
	try {
		await axios.post(url, values)
	} catch (e: unknown) {
		const error = e as { response?: { request?: { responseText?: string } } }
		showError(t('groupsharemachine', 'Failed to share') + `: ${error.response?.request?.responseText ?? ''}`)
		console.debug(e)
		return
	}
	showSuccess(t('groupsharemachine', 'Shared'))

	const shareTab = OCA.Files?.Sidebar?.state?.tabs?.find((tab: { id: string }) => tab.id === 'sharing')
	if (shareTab) {
		shareTab.update(props.fileInfo)
	}
}

onBeforeMount(() => {
	console.debug('GroupShareMachine: preparing grouplisting')
	getContent()
})
</script>

<style lang="scss" scoped>
.grid {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr;
	grid-template-rows: repeat(auto-fill, auto);
	position: relative;
	margin: 0.5rem 0;
	row-gap: 0.5rem;
}

.widgetheaderdiv {
	display: flex;
	align-items: baseline;
}

.widgetheader {
	white-space: nowrap;
	padding-right: 2rem;
}
</style>
