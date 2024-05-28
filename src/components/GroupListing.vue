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
				:value.sync="filtertext"
				:label="t('groupsharemachine', 'Filter groups')"
				trailing-button-icon="close"
				:show-trailing-button="filtertext !== ''"
				@trailing-button-click="clearFilter">
				<Magnify :size="18" />
			</NcTextField>
		</div>
		<div ref="newItem"
			class="grid"
			:title="t('groupsharemachine', 'Share to a group')"
			:bold="false"
			:force-display-actions="true">
			<NcButton v-for="item in sortedFilteredContent"
				:key="item.abbreviation"
				:aria-label="item.name"
				:groupabbrv="item.abbreviation"
				type="primary"
				@click="shareContent(item.abbreviation)">
				{{ item.name }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import axios from '@nextcloud/axios'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'GroupListing',

	components: {
		NcButton,
		NcTextField,
		Magnify,
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
			filtertext: '',
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
		sortedFilteredContent() {
			return this.filteredContent.toSorted((a, b) => (a.name.toLowerCase().trim().localeCompare(b.name.toLowerCase().trim())))
		},
		filteredContent() {
			if (this.filtertext.length === 0) {
				return this.content
			}
			return this.content.filter((p) => { return p.name.toLowerCase().includes(this.filtertext.toLowerCase()) })
		},
	},

	beforeMount() {
		console.debug('preparing grouplisting')
		this.getContent()
	},

	methods: {
		clearFilter() {
			this.filtertext = ''
		},

		async getContent() {
			const gurl = generateUrl('/apps/groupsharemachine/puavoGroups')
			try {
				const response = await axios.get(gurl)
				if (response.data.length > 0) {
				  this.content = this.content.concat(response.data[0])
				  console.debug('"' + JSON.stringify(response.data[0]) + '"')
				} else {
				  console.debug('no groups, probably not a teacher (response ' + JSON.stringify(response.data) + ')')
				}
			} catch (error) {
				console.debug(error)
			}
			this.loading = false
		},

		async shareContent(target) {
			const groupsearchUrl = generateUrl('/apps/groupsharemachine/searchNextcloudGroups/', 2) + target
			try {
				const res = await axios.get(groupsearchUrl)
				console.debug('"' + JSON.stringify(res) + '"')
				if (res.data.length > 0) {
					console.debug('found possible matching groups, using first one: "' + JSON.stringify(res.data) + '"')
					target = res.data[0]
				} else {
					showError(t('groupsharemachine', 'Failed to share') + ': ' + t('groupsharemachine', 'no matching groups found for {group}', { group: target }))
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
