<!--
SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div ref="newItem"
		class="grid"
		:title="t('cfg_share_links', 'Custom public link')"
		:bold="false"
		:force-display-actions="true">
		<NcButton aria-label="Example text"
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
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

export default {
	name: 'GroupListing',

	components: {
		NcButton,
	},

	mixins: [
	],

	props: {
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
