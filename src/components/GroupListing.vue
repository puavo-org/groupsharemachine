<!--
SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<h2 v-if="content.length > 0"> {{ t('groupsharemachine', 'Share to a group') }}</h2>
		<div ref="newItem"
			class="grid"
			:title="t('groupsharemachine', 'Share to a group')"
			:bold="false"
			:force-display-actions="true">
			<NcButton v-for="item in content"
				:key="item.abbreviation"
				:disabled="disabled"
				:readonly="readonly"
				:aria-label="item.name"
				:groupabbrv="item.abbreviation"
				type="primary"
				@click="shareContent(item.abbreviation)">
				<template v-if="style.indexOf('text') !== -1">
					{{ item.name }}
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

	mixins: [
	],

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
			loading: true,
			content: [],
		}
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

	beforeMount() {
		console.debug('preparing grouplisting')
		this.getContent()
	},

	methods: {
		async getContent() {
			const gurl = generateUrl('/apps/groupsharemachine/puavoGroups')
			try {
				const response = await axios.get(gurl)
				this.content = this.content.concat(response.data[0])
				this.number = this.content.length
				console.debug('"' + JSON.stringify(response.data[0]) + '"')
			} catch (error) {
				console.debug(error)
			}
			this.loading = false
		},

		async shareContent(target) {
			const groupsearchUrl = generateOcsUrl('cloud/groups?search=', 2) + target
			try {
				const res = await axios.get(groupsearchUrl)
				if (res.data.ocs.data.groups.length > 0) {
					console.debug('found possible matching groups, using first one: "' + JSON.stringify(res.data.ocs.data.groups) + '"')
					target = res.data.ocs.data.groups[0]
				} else {
					showError(t('groupsharemachine', 'Failed to share') + ':' + t('groupsharemachine', 'no matching groups found for ') + target) // TODO translations
					return
				}
			} catch (e) {
				showError(t('groupsharemachine', 'Failed to search for group') + `: ${e.response?.request?.responseText ?? ''}`)
				console.debug(e)
				return
			}

			const values = {
			  path: this.getFullPath,
			  shareType: 1,
			  permissions: 1,
			  shareWith: target,
			} // https://github.com/nextcloud/documentation/blob/master/developer_manual/client_apis/OCS/ocs-share-api.rst
			const url = generateOcsUrl('apps/files_sharing/api/v1/shares', 2)
			console.debug('"' + JSON.stringify(values) + '"')
			try {
				await axios.post(url, values)
			} catch (e) {
				showError(t('groupsharemachine', 'Failed to share') + `: ${e.response?.request?.responseText ?? ''}`)
				console.debug(e)
			}
			showSuccess(t('groupsharemachine', 'Shared'))

			const shareTab = OCA.Files.Sidebar.state.tabs.find(e => e.id === 'sharing')
			if (shareTab) {
				shareTab.update(this.fileInfo)
			}
		},
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
