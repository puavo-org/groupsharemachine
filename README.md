<!--
SPDX-FileCopyrightText: Tuomas Nurmi <dev@opinsys.fi>
SPDX-License-Identifier: CC0-1.0
-->

# Group Share Machine

A Nextcloud app that provides a list of one-click buttons for sharing files to different groups in Nextcloud sharing tab.
The rationale is to allow teachers to share files with students in any class group, without being members of the class groups themselves. This is implemented
with a Puavo integration that checks the account corresponding to logged in user from Puavo, and if it is a teacher, provides a list of classes
in the teacher's school. Any usable functionality requires a Puavo instance, and Nextcloud accounts with usernames corresponding to Puavo usernames,
or alternatively Puavo ids.

Place this app in **nextcloud/apps/**

## Building the app

The app can be built by using the provided Makefile by running:

    make

and a ready-for-server package with

    make && make appstore

One should refer to more complete and up-to-date sources (e.g. https://github.com/nextcloud/app_template) for any extra insight on Nextcloud app
development and distribution.
