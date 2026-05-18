// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Based on https://github.com/jimmyl0l3c/cfg_share_links/cfg_share_links/src/reg_new_link.js

import { createApp, reactive, h, type App } from 'vue'
import GroupListing from './components/GroupListing.vue'

console.debug('GroupShareMachine: init GroupListing')

let appInstance: App | null = null
let props: { fileInfo: Record<string, unknown> } | null = null

window.addEventListener('DOMContentLoaded', () => {
	if (OCA.Sharing?.ShareTabSections) {
		OCA.Sharing.ShareTabSections.registerSection(
			(el: HTMLElement[], fileInfo: Record<string, unknown>) => {
				if (typeof fileInfo !== 'undefined' && typeof el !== 'undefined') {
					if (appInstance && props && window.document.contains(el[0])) {
						// Update existing reactive props
						props.fileInfo = fileInfo
					} else {
						// Unmount old instance if it exists
						if (appInstance) {
							appInstance.unmount()
						}

						props = reactive({ fileInfo })

						appInstance = createApp({
							render() {
								return h(GroupListing, { fileInfo: props!.fileInfo })
							},
						})

						appInstance.mount(el[0])
					}
				}
			},
		)
	}
})
