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

## Installation

This app is installed manually — it is not distributed through the Nextcloud App Store, so `occ app:install` will not find it. Installing means putting the app directory on the server yourself and enabling it.

There is nothing to compile and no runtime dependencies: the app is plain PHP, and Nextcloud autoloads `OCA\GroupShareMachine\` from `lib/` on its own. Do **not** run `composer install` on the server — the Composer setup in this repo only installs development tools.

Commands below assume `/var/www/html` as the Nextcloud root and `www-data` as the web server user; adjust to your deployment. Run every `occ` command as the web server user (`sudo -u www-data php occ ...`).

### 1. Get the app onto the server

Either build a tarball from a checkout (on any machine with `make`):

```bash
git clone https://github.com/puavo-org/groupsharemachine.git
cd groupsharemachine
git checkout v1.0.0          # or the version you want
make                         # -> build/groupsharemachine.tar.gz
```

The tarball contains only what the app needs at runtime (`appinfo/`, `lib/`, `img/`, licences) and unpacks to a single `groupsharemachine/` directory.

Then unpack it into the Nextcloud apps directory:

```bash
sudo tar xzf groupsharemachine.tar.gz -C /var/www/html/apps/
sudo chown -R www-data:www-data /var/www/html/apps/groupsharemachine
```

Or copy a checkout straight into place, if you prefer to skip the tarball:

```bash
sudo rsync -a --delete \
    --exclude '.git' --exclude 'build' --exclude 'tests' \
    --exclude 'vendor' --exclude 'vendor-bin' \
    groupsharemachine/ /var/www/html/apps/groupsharemachine/
sudo chown -R www-data:www-data /var/www/html/apps/groupsharemachine
```

Any directory listed in the `apps_paths` setting in `config/config.php` works, not just `apps/`. If you use a custom path, make sure its `writable` flag and ownership match how you manage the rest of your apps. Note that a **symlink** into the apps directory does not work reliably (and breaks entirely in containerised setups) — copy or bind-mount the real directory.

Verify Nextcloud sees it:

```bash
sudo -u www-data php occ app:list | grep -A1 -i disabled | head
# groupsharemachine should appear under "Disabled:"
```

### 2. Enable and configure

```bash
# enable the app — this also runs the migration that creates
# oc_groupsharemachine_groups and oc_groupsharemachine_teachers
sudo -u www-data php occ app:enable groupsharemachine

# turn on the standard restriction so non-teachers stay constrained
sudo -u www-data php occ config:app:set core shareapi_only_share_with_group_members --value=yes

# do an initial group-type sync (afterwards a background job runs it every 15 minutes)
sudo -u www-data php occ groupsharemachine:sync
```

`occ groupsharemachine:sync` reports one line per table, e.g.:

```
Groups:   seen=500 kept=500 pruned=0
Teachers: seen=1000 kept=1020 pruned=0
```

Reading the counters:

- `seen` — LDAP entries returned by the search. The filter already restricts to class-like `puavoEduGroupType` values / to users with `puavoEduPersonAffiliation: teacher`, so `seen` is the number of relevant entries, not the size of the directory.
- `kept` — rows written. For groups this normally equals `seen`; a lower number means some DNs did not resolve to a Nextcloud gid. For teachers it counts **(teacher, school) pairs**, so it is legitimately *higher* than `seen` when teachers belong to more than one school — and lower when teachers have no school assigned, since those cannot share to any class and are skipped.
- `pruned` — rows deleted because they no longer exist in LDAP. Nonzero after groups or teachers are removed upstream.

`kept=0` on either line means nothing matched — see the [Requirements](#requirements) above and the troubleshooting notes in [`DEVELOPMENT.md`](DEVELOPMENT.md).

The 15-minute refresh runs as a Nextcloud background job, so it only happens if `cron.php` (or AJAX/webcron) is actually running on the instance — check with `occ background-job:list`.

### 3. Verify

```bash
sudo -u www-data php occ groupsharemachine:diagnose <teacher-uid> <class-gid>
```

`virtualised by this app: YES` confirms the wiring end to end. See [Testing](#testing) below for the full check, including the negative case for students.

### Upgrading

Replace the app directory with the new version and let Nextcloud run any pending migrations:

```bash
sudo -u www-data php occ maintenance:mode --on
sudo rm -rf /var/www/html/apps/groupsharemachine
sudo tar xzf groupsharemachine.tar.gz -C /var/www/html/apps/
sudo chown -R www-data:www-data /var/www/html/apps/groupsharemachine
sudo -u www-data php occ upgrade
sudo -u www-data php occ maintenance:mode --off
```

The app's own settings and tables survive the swap; there is no need to re-run the sync by hand, though `occ groupsharemachine:sync` is harmless and gives immediate feedback.

### Removing

```bash
sudo -u www-data php occ app:disable groupsharemachine
sudo rm -rf /var/www/html/apps/groupsharemachine
```

Disabling is enough to stop the membership virtualisation — from then on teachers are subject to the same share restriction as everyone else, so re-check that this is what you want before disabling on a production instance. The two `oc_groupsharemachine_*` tables are left in the database; they hold only cached LDAP-derived data and can be dropped manually if you are done with the app for good.

## Testing

Pre-requisite: a Puavo LDAP populated with at least one teacher (`puavoEduPersonAffiliation` containing `teacher`) and one class group (`puavoEduGroupType` set to `year class`, `teaching group`, or `course group`).

1. Run `occ groupsharemachine:sync` — expect non-zero `kept` for both groups and teachers.
2. `occ groupsharemachine:diagnose <teacher-uid> <class-gid>` — `virtualised by this app: YES` confirms the wiring.
3. Log in as a teacher, open any file's sharing sidebar, type a class group name. The group should appear in the autocomplete and the share should succeed.
4. Log in as a student, try sharing to a different class group — it should be rejected with "Sharing is only allowed within your own groups".

## How it works internally

For contributor-facing details — the two custom tables (`oc_groupsharemachine_groups`, `oc_groupsharemachine_teachers`), the share-check and picker code paths we hook into, sync mechanics, and conventions — see [`AGENTS.md`](AGENTS.md).

For the Nextcloud side of it — how the share dialog is answered, why the picker and the share check are separate mechanisms, and why calendars behave differently — see [`SHARING-INTERNALS.md`](SHARING-INTERNALS.md).

## Releasing a new version

1. Update the version in `appinfo/info.xml`
2. Commit and tag: `git commit -m "vx.x.x" && git tag vx.x.x && git push && git push --tags`
3. Build and sign the package: `make sign` (requires certs in `~/.nextcloud/certificates/`; signing keeps `occ integrity:check-app groupsharemachine` clean on installs)
4. Attach `build/groupsharemachine.tar.gz` to the GitHub release for the tag — that tarball is what admins install by hand (see [Installation](#installation))
