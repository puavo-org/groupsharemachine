<!--
SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
		<div>
		<h2> {{ t('groupsharemachine', 'Share to a group') }}</h2>
			<div ref="newItem"
				class="grid"
				:title="t('groupsharemachine', 'Share to a group')"
				:bold="false"
				:force-display-actions="true">
				<NcButton aria-label="Example text" @click="shareContent"
				:disabled="disabled"
				:readonly="readonly"
				type="primary">
				<template v-if="style.indexOf('text') !== -1">
					Group 1
				</template>
			</NcButton>
			<NcButton aria-label="Example text 2"
				:disabled="disabled"
				:readonly="readonly"
				type="primary">
				<template v-if="style.indexOf('text') !== -1">
					Group 2
				</template>
			</NcButton>
			<NcButton aria-label="Example text 3"
				:disabled="disabled"
				:readonly="readonly"
				type="primary">
				<template v-if="style.indexOf('text') !== -1">
					Group 3
				</template>
			</NcButton>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import axios from '@nextcloud/axios'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'


export default {
	name: 'GroupListing',

	components: {
		NcButton,
	},
	computed: {
		getFullPath() { // From cfg_share_links/src/components/NewLink.vue
			if (this.fileInfo) {
				if (this.fileInfo.path.endsWith('/')) {
					return this.fileInfo.path.concat(this.fileInfo.name)
				} else {
					return this.fileInfo.path.concat('/', this.fileInfo.name)
				}
			} else {
				return 'None'
			}
		},
		canShare() { // From cfg_share_links/src/components/NewLink.vue
			return !!(this.fileInfo.permissions & OC.PERMISSION_SHARE)
		},
	},

	mixins: [
	],

	methods: {
		async shareContent() {
					const gurl = generateUrl('/apps/groupsharemachine/puavoGroups')
			try {
				const response = await axios.get(gurl)
				console.debug('"' + JSON.stringify(response.data) + '"')
			} catch (e) {
				console.debug(e)
			}

			const values = {
			  path: this.getFullPath,
			  shareType: 1,
			  permissions: 1,
			  shareWith: "testi4",
			} // https://github.com/nextcloud/documentation/blob/master/developer_manual/client_apis/OCS/ocs-share-api.rst
			const req = {
				values,
			}
			const url = generateOcsUrl('apps/files_sharing/api/v1/shares', 2)
			console.debug('"' + JSON.stringify(values) + '"')
			try {
				await axios.post(url, values)
			} catch (e) {
				showError(t('groupsharemachine', 'Failed to save external portal options') + `: ${e.response?.request?.responseText ?? ''}`)
				console.debug(e)
			}
			showSuccess(t('groupsharemachine', 'Shared'))

			const shareTab = OCA.Files.Sidebar.state.tabs.find(e => e.id === 'sharing')
			if (shareTab) {
				shareTab.update(this.fileInfo)
			}
		},
	},

	props: {
			fileInfo: {
			type: Object,
			default: () => {},
			required: true,
		},
	},
	data() {
		return {
			disabled: false,
			style: 'icontext',
		}
	},
}
</script>

<style lang="scss" scoped>
.grid {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr 1fr;
	grid-template-rows: repeat(auto-fill, auto);
	position: relative;
	margin: 0.5rem 0;
}
</style>
