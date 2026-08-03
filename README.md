<!--
SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
SPDX-License-Identifier: CC0-1.0
-->

# Group Share Machine

> **Puavo-specific.** This app is only useful in deployments that synchronise users and groups from a [Puavo](https://github.com/puavo-org) LDAP instance via `user_ldap`, with the `puavoEdu*` schema (`puavoEduPersonAffiliation` on users, `puavoEduGroupType` on groups). It is not a general-purpose sharing helper.

A Nextcloud app that lets teachers share files to class groups through Nextcloud's **native** sharing dialog — including from the mobile and desktop clients. It does this by virtualising teacher membership in class-like groups so the standard share check passes, without weakening Nextcloud's "only share with group members" restriction for everyone else.

## How it works

1. The admin enables `shareapi_only_share_with_group_members` (default off in Nextcloud). With that on, normal users can only share to groups they're a member of.
2. A background job walks LDAP via `user_ldap`'s proxies and refreshes two local tables:
   - `oc_groupsharemachine_groups` — gids whose `puavoEduGroupType` is `year class`, `teaching group`, or `course group`
   - `oc_groupsharemachine_teachers` — uids whose multi-valued `puavoEduPersonAffiliation` includes the value `teacher`
3. A custom group backend then reports, for every recorded class group, that any recorded teacher is a member — but only for sharing checks. The class groups do **not** show up in the teacher's group list and the teacher does **not** auto-receive shares directed at them.

The net effect: teachers can pick any class group from the native sharee picker and share to it; students still can't share outside their own groups; the mobile and desktop clients work without any app-specific UI.

Reading `puavoEduPersonAffiliation` directly (rather than via Nextcloud's `role` account property) is intentional — that attribute is multi-valued in puavo LDAP and `user_ldap` flattens it during sync, so an `admin` + `teacher` user could end up classified as `admin` only and lose their teacher status.

## Requirements

- Nextcloud 31, 32, or 33
- PHP 8.1+
- A **Puavo** LDAP instance, reachable from Nextcloud
- `user_ldap` enabled and bound against that Puavo LDAP — without this the app has nothing to read and `occ groupsharemachine:sync` reports `seen=0`
- The `puavoEduPersonAffiliation` and `puavoEduGroupType` attributes are readable by the user_ldap bind agent (the app reads them on demand via user_ldap's connection — no extra LDAP credentials needed)
- Groups in LDAP have `puavoEduGroupType` set to `year class`, `teaching group`, or `course group` for the classes teachers should be allowed to share to
- The admin setting **Sharing → Restrict users to only share with users in their groups** turned on (or `occ config:app:set core shareapi_only_share_with_group_members --value=yes`) — without this the restriction the app bypasses doesn't exist in the first place

## Setup

```bash
# enable the app
occ app:enable groupsharemachine

# turn on the standard restriction so non-teachers stay constrained
occ config:app:set core shareapi_only_share_with_group_members --value=yes

# do an initial group-type sync (otherwise it runs every 15 minutes)
occ groupsharemachine:sync
```

`occ groupsharemachine:sync` reports something like `seen=482 kept=37 pruned=0`.

## Testing

Pre-requisite: a Puavo LDAP populated with at least one teacher (`puavoEduPersonAffiliation` containing `teacher`) and one class group (`puavoEduGroupType` set to `year class`, `teaching group`, or `course group`).

1. Run `occ groupsharemachine:sync` — expect non-zero `kept` for both groups and teachers.
2. `occ groupsharemachine:diagnose <teacher-uid> <class-gid>` — `virtualised by this app: YES` confirms the wiring.
3. Log in as a teacher, open any file's sharing sidebar, type a class group name. The group should appear in the autocomplete and the share should succeed.
4. Log in as a student, try sharing to a different class group — it should be rejected with "Sharing is only allowed within your own groups".

## How it works internally

For contributor-facing details — the two custom tables (`oc_groupsharemachine_groups`, `oc_groupsharemachine_teachers`), the share-check and picker code paths we hook into, sync mechanics, and conventions — see [`AGENTS.md`](AGENTS.md).

## Releasing a new version

1. Update the version in `appinfo/info.xml`
2. Commit and tag: `git commit -m "vx.x.x" && git tag vx.x.x && git push && git push --tags`
3. Build and sign the appstore package: `make sign` (requires certs in `~/.nextcloud/certificates/`)
4. Upload `build/groupsharemachine.tar.gz` to the [Nextcloud App Store](https://apps.nextcloud.com/developer/apps/releases/new)
