// SPDX-FileCopyrightText: Tuomas Nurmi <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later
const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')


webpackConfig.entry = {
  groupsharemachine: path.join(__dirname, 'src', 'show_groupbox.js'),
}

module.exports = webpackConfig
