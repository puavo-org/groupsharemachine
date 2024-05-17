// SPDX-FileCopyrightText: Filip Joska <filip@joska.dev>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Based on https://github.com/jimmyl0l3c/cfg_share_links/cfg_share_links/src/reg_new_link.js

import Vue from 'vue'
import GroupListing from './components/GroupListing.vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

// Vue.prototype.OC = window.OC
Vue.prototype.OCA = window.OCA

Vue.mixin({
	methods: {
		t,
		n,
	},
})

console.debug('GroupShareMachine: init GroupListing')

// Add new section
let sectionInstance = null
let props = null
const View = Vue.extend(GroupListing)

window.addEventListener('DOMContentLoaded', function() {
	if (OCA.Sharing && OCA.Sharing.ShareTabSections) {
		OCA.Sharing.ShareTabSections.registerSection(
			(el, fileInfo) => {
				if (typeof fileInfo !== 'undefined' && typeof el !== 'undefined') {
					// if instance exists, just update props
					if (sectionInstance && window.document.contains(sectionInstance.$el) && props) {
						// props.fileInfo = fileInfo
					} else { // create new instance
						if (sectionInstance) {
							// if sectionInstance.$el doesnt exist anymore (after changing folder for example)
							sectionInstance.$destroy()
						}

						sectionInstance = new View({
							props: { },
						})

						props = Vue.observable({
							...sectionInstance._props,
						})
						sectionInstance._props = props

						sectionInstance.$mount(el[0])
					}
				}
			})
	}
})
