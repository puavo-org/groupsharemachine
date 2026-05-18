<!--
SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
SPDX-License-Identifier: CC0-1.0
-->

# Group Share Machine

A Nextcloud app that displays a grid of one-click share buttons in the file sharing sidebar so teachers can instantly share files with any class group. Teachers are identified by membership in groups with the `teachers_` prefix. Class groups are discovered from Nextcloud groups with the `class_` prefix.

## How it works

The app adds a panel to the file sharing sidebar with one-click share buttons for class groups.

- **Teacher detection**: The current user must belong to at least one Nextcloud group with the `teachers_` prefix (e.g. `teachers_schoolA`). Users without such membership see nothing.
- **Group discovery**: All Nextcloud groups with the `class_` prefix (e.g. `class_1a`) are shown as share buttons.
- **Sharing**: Clicking a button shares the file read-only to that group using the standard Nextcloud sharing API.

## Testing

Create test groups and users via `occ`:

```bash
# Teacher group
occ group:add teachers_schoolA

# Class groups
occ group:add class_1a
occ group:add class_1b
occ group:add class_2a

# Users
occ user:add teacher1
occ user:add student1
occ user:add student2

# Assign memberships
occ group:addmember teachers_schoolA teacher1
occ group:addmember class_1a student1
occ group:addmember class_1a student2
occ group:addmember class_1b student2
```

Expected behavior:
- `teacher1` opens file sharing sidebar and sees buttons for Class 1A, 1B, 2A
- `student1` or any user not in a `teachers_` group sees nothing

## Requirements

- Nextcloud 31 or 32
- PHP 8.1+
- Node.js 20+

## Building the app

Install dependencies and build:

```bash
npm install
npm run build
```

For development with file watching:

```bash
npm run watch
```

## Linting

```bash
npm run lint
npm run stylelint
composer cs:check
composer psalm
```

## Releasing a new version

1. Update the version in `appinfo/info.xml` and `package.json`
2. Commit and tag the release:
   ```bash
   git add appinfo/info.xml package.json
   git commit -m "vx.x.x"
   git tag vx.x.x
   git push && git push --tags
   ```
3. Build and sign the appstore package:
   ```bash
   make sign
   ```
   This uses `docker exec` to run `occ integrity:sign-app` inside the
   `master_nextcloud_1` container. Signing certificates must be placed in
   `~/.nextcloud/certificates/` (`groupsharemachine.key` and `groupsharemachine.crt`).

   To build without signing, run `make` instead.
4. Upload `build/groupsharemachine.tar.gz` to the [Nextcloud App Store](https://apps.nextcloud.com/developer/apps/releases/new)
