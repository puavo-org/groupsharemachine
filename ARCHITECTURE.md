<!--
SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
SPDX-License-Identifier: CC0-1.0
-->

# Architecture

This document explains how the app integrates with Nextcloud at runtime, the two tables it adds, and how they relate to existing Nextcloud and `user_ldap` tables. Pair this with `README.md` (operator-facing overview) and `DEVELOPMENT.md` (local dev setup).

## Goal recap

Let users with `puavoEduPersonAffiliation=teacher` share files to class groups (`puavoEduGroupType ∈ {year class, teaching_group}`) through Nextcloud's **native** share UI, without:

- weakening the `shareapi_only_share_with_group_members=yes` restriction for everyone else,
- patching the Nextcloud server,
- adding a custom frontend (mobile + desktop clients must work too).

## The two paths we hook into

Nextcloud uses **two distinct code paths** for a group share — the picker (autocomplete in the share dialog) and the create-check (when the share is actually submitted). We extend both:

| NC code path | Source class | What we add |
|---|---|---|
| Sharee picker | `OC\Collaboration\Collaborators\GroupPlugin` | `OCA\GroupShareMachine\Collaboration\TeacherClassSearchPlugin` — adds class groups to picker results when the searcher is a teacher |
| Share create-check | `OC\Share20\Manager::groupCreateChecks()` | `OCA\GroupShareMachine\GroupBackend\TeacherClassMembership` — a custom `OCP\GroupInterface` that reports teachers as virtual members of class groups |

Both paths consult the two local tables this app maintains. The picker side bypasses GroupPlugin's "only show groups you're in" filter; the create-check side satisfies the existing `IGroup::inGroup($sharedBy)` requirement.

## Share-check sequence

```
                                       ┌─────────────────────────────────────────────┐
  POST /ocs/.../shares                  │ Share20\Manager::groupCreateChecks()        │
  (or native share dialog click)        │                                             │
  ─────────────────────────────────────▶│  if shareapi_only_share_with_group_members: │
                                       │     $sharedWith = groupManager->get(gid)    │
                                       │     if !$sharedWith->inGroup($sharedBy):    │
                                       │         throw "only your own groups"        │
                                       └──────────────┬──────────────────────────────┘
                                                      │ inGroup() iterates all backends
                                                      │ that previously claimed groupExists($gid)
                                                      ▼
   ┌─────────────────────────┐                 ┌────────────────────────────────────┐
   │ user_ldap Group_Proxy   │  YES if real    │ TeacherClassMembership (this app)  │
   │ inGroup(uid, gid)       │  LDAP member ───┤ inGroup(uid, gid):                 │
   └─────────────────────────┘                 │   = group_in_table AND uid_in_table│
                                                └──────────────────┬─────────────────┘
                                                                   │
                                                  any YES → share allowed
```

Key insight: **`Group::inGroup()` is OR-aggregated across all backends that previously claimed `groupExists($gid)`**. Our backend must claim existence for the class group (`groupExists()` returns `true` for any gid in `oc_groupsharemachine_groups`), otherwise its `inGroup()` is never called. This is a Nextcloud subtlety that's easy to miss.

## Picker sequence

```
   typing "nct" in share dialog
   ▼
   GET /ocs/.../sharees?search=nct&shareType[]=1
   ▼
   OC\Collaboration\Collaborators\Search::search()
   │   collects results from every registered plugin for shareType=GROUP
   │
   ├──▶ GroupPlugin (NC core)
   │       searches oc_groups / user_ldap, then filters out groups NOT in
   │       IGroupManager::getUserGroupIds($searcher)  ←  this filter excludes
   │       class groups for teachers because our getUserGroups() returns []
   │
   └──▶ TeacherClassSearchPlugin (this app)
           if searcher is in oc_groupsharemachine_teachers:
              SELECT gid FROM oc_groupsharemachine_groups WHERE gid LIKE %search%
              add each as a SearchResultType('groups') entry
              (NOT filtered by GroupPlugin's user-groups rule — different plugin)
```

The picker plugin only runs for users marked as teachers, so the negative path (students, admins-without-teacher-affiliation) is unaffected — they see only the groups they actually belong to.

## Why `getUserGroups()` is deliberately empty

`TeacherClassMembership::getUserGroups($uid)` returns `[]` even for teachers. If it returned the class groups instead:

- The teacher would see those classes in their profile's "Your groups" list.
- The teacher would auto-receive shares directed to those classes (because `IGroupManager::getUserGroupIds()` joins to `oc_share.share_with`).

Neither is desired. The asymmetry — teachers can *send* to a class but don't *receive* from it — is intentional, matching the puavo workflow ("teachers share material; students consume it via class membership").

## The two tables

Both live in Nextcloud's main database, both have the `oc_` prefix applied by NC's table-prefix mechanism (so the actual SQL names are `oc_groupsharemachine_groups` and `oc_groupsharemachine_teachers` when the prefix is `oc_`).

### `groupsharemachine_groups`

```sql
CREATE TABLE groupsharemachine_groups (
    gid         VARCHAR(64) NOT NULL,    -- Nextcloud gid (= user_ldap owncloud_name)
    group_type  VARCHAR(64) NOT NULL,    -- 'year class' | 'teaching_group'
    PRIMARY KEY (gid),
    INDEX gsm_groups_type_idx (group_type)
);
```

One row per LDAP group whose `puavoEduGroupType` is in the allow-list. The `gid` is the same string Nextcloud uses everywhere else (`oc_groups.gid`, `oc_group_user.gid`, `oc_share.share_with`, etc.). Populated by `Service\LdapSync::syncGroups()`.

### `groupsharemachine_teachers`

```sql
CREATE TABLE groupsharemachine_teachers (
    uid  VARCHAR(64) NOT NULL,           -- Nextcloud uid (= user_ldap owncloud_name)
    PRIMARY KEY (uid)
);
```

One row per LDAP user whose multi-valued `puavoEduPersonAffiliation` contains `teacher`. Populated by `Service\LdapSync::syncTeachers()`.

## How our tables relate to Nextcloud's tables

```
                          ┌──────────────────────────────────────┐
                          │           LDAP (puavo)               │
                          │  users:  uid, puavoEduPersonAffiliation,│
                          │          puavoSchool, ...            │
                          │  groups: cn, puavoEduGroupType, ...  │
                          └──────────────┬───────────────────────┘
                                         │ user_ldap sync (identity only)
                                         ▼
   ┌─────────────────────┐    ┌──────────────────────────────────┐
   │ oc_users            │    │ oc_ldap_group_mapping            │
   │   uid               │    │   owncloud_name (= gid)          │
   │ oc_accounts         │    │   ldap_dn                        │
   │   uid, data (JSON)  │    │   directory_uuid                 │
   │ oc_accounts_data    │    │ oc_groups (gid only)             │
   │   uid, name, value  │    │ oc_group_user (gid, uid)         │
   └──────────┬──────────┘    └────────────────┬─────────────────┘
              │                                 │
              │ user_ldap stores identity +    │ user_ldap stores
              │ a single flattened 'role'      │ identity only —
              │ (loses other affiliations)     │ NO puavoEduGroupType
              │                                 │
              ▼                                 ▼
                  ╔═════════════════════════════════════════╗
                  ║   Service\LdapSync (every 15 minutes)   ║
                  ║                                         ║
                  ║   For each LDAP user / group:           ║
                  ║     Access::readAttribute(dn, attr)     ║
                  ║   Writes matching rows into our tables  ║
                  ╚════════════════╤════════════════════════╝
                                   │
                                   ▼
                   ┌─────────────────────────────────┐
                   │ oc_groupsharemachine_teachers   │  ← read by isTeacher() in
                   │ oc_groupsharemachine_groups     │     TeacherClassMembership +
                   └─────────────────────────────────┘     TeacherClassSearchPlugin
```

**What we read from Nextcloud:** nothing directly. We never query `oc_share`, `oc_groups`, `oc_users`, `oc_accounts`, or `oc_ldap_group_mapping` from this app's code. All identity/group enumeration happens through `IGroupManager` and `user_ldap`'s `Group_Proxy` / `User_Proxy`.

**What we write to Nextcloud:** only our own two tables. We don't modify any NC-owned table.

**What Nextcloud reads from us:** indirectly, through the `OCP\GroupInterface` we register (`IGroupManager::addBackend()`) and the `ISearchPlugin` we register (`OCP\Collaboration\Collaborators\ISearch::registerPlugin()`). NC asks our backend questions; we answer using our tables.

## Why two tables instead of one

The schema is symmetric on purpose:

| concern | how the schema handles it |
|---|---|
| Multi-valued `puavoEduPersonAffiliation` (e.g. `admin` + `teacher`) | The teacher table records the uid if **any** value in the multi-valued attribute is `teacher`. `user_ldap`'s sync to `oc_accounts.data.role` would have picked only one value and dropped the others. |
| Multi-school deployments | Each row is just `(gid)` or `(uid)` — neither table is school-scoped. If teachers across multiple schools should share to each other's classes, that's just whatever the LDAP filter returns. If you want to restrict, narrow the user_ldap user filter (which user_ldap's `User_Proxy::getUsers()` respects). |
| Sync staleness | A row that no longer matches the filter is dropped on the next sync (`deleteNotIn()`). Worst-case staleness is the TimedJob interval (15 min). On-demand: `occ groupsharemachine:sync`. |
| Deletion safety | Both tables are app-owned. App removal drops them via the migration step. NC's identity tables are untouched. |

## What populates the tables

`Service\LdapSync` runs from two entry points:

- **`occ groupsharemachine:sync`** — `Command\SyncGroupTypesCommand`, for on-demand refresh.
- **`SyncGroupTypesJob`** — `OCP\BackgroundJob\TimedJob`, every 15 minutes; registered in `appinfo/info.xml` under `<background-jobs>`.

Both call `LdapSync::run()`, which executes two sync passes:

1. **Groups pass.** `OCA\User_LDAP\Group_Proxy::getGroups('', 500, $offset)` enumerates all LDAP-backed groups in pages. For each gid, `Access::groupname2dn($gid)` resolves the DN and `Access::readAttribute($dn, 'puavoEduGroupType')` reads the (potentially multi-valued) attribute. If any value is in `LdapSync::ALLOWED_GROUP_TYPES`, the gid is upserted with that value; otherwise the row is removed. After the walk, `deleteNotIn($kept)` prunes anything that disappeared.

2. **Teachers pass.** Same shape, but with `User_Proxy::getUsers()` + `Access::username2dn()` + `readAttribute('puavoEduPersonAffiliation')`. Match condition is "teacher appears anywhere in the value list".

`user_ldap`'s `Group_Proxy` / `User_Proxy` / `Access` aren't OCP-public — we resolve them lazily (`class_exists` + `Server::get`) and the sync becomes a no-op if `user_ldap` is absent.

## Extension points (Nextcloud APIs)

What this app uses from Nextcloud:

| API | Purpose |
|---|---|
| `OCP\AppFramework\Bootstrap\IBootstrap` | App boot lifecycle |
| `OCP\IGroupManager::addBackend()` | Register `TeacherClassMembership` as a group backend |
| `OCP\Collaboration\Collaborators\ISearch::registerPlugin()` | Register `TeacherClassSearchPlugin` for `IShare::TYPE_GROUP` |
| `OCP\GroupInterface` | Implemented by `TeacherClassMembership` |
| `OCP\Collaboration\Collaborators\ISearchPlugin` | Implemented by `TeacherClassSearchPlugin` |
| `OCP\AppFramework\Db\{QBMapper,Entity}` | DB layer for our tables |
| `OCP\BackgroundJob\TimedJob` | Periodic sync |
| `OCP\Server` | Lazy resolution of `user_ldap` internals (only because they're not OCP) |

What this app does **not** use (deliberately):

- `OCP\Accounts\IAccountManager` and the `role` account property — bypassed because it's single-valued and lossy. We read `puavoEduPersonAffiliation` directly from LDAP via the sync.
- `OCP\Share\IManager` — we don't create or modify shares; we just satisfy the existing share-check.
- `OCP\Notification\IManager` — no notifications. Share creation already produces the standard NC notification.

## Operator diagnostic

`occ groupsharemachine:diagnose <uid> <gid>` is the single source of truth for "why isn't this teacher able to share to that class?". It prints:

- Whether the uid is in `oc_groupsharemachine_teachers`
- Whether the gid is in `oc_groupsharemachine_groups`
- Whether `IGroupManager::get($gid)` resolves the group at all (NC sees it)
- Whether the **app's backend alone** would allow virtual membership
- Whether the **aggregate** `IGroup::inGroup()` returns YES (any backend, including real LDAP membership via user_ldap)

The aggregate is what `Share20\Manager::groupCreateChecks()` consults — a YES via real LDAP membership is just as valid as a YES via our virtualised membership.
