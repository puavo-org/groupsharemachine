<!--
SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
SPDX-License-Identifier: CC0-1.0
-->

# Agent guide

This file briefs AI coding assistants on the project's conventions, runtime model, and gotchas. If you're a human, the contents may still be useful — but `README.md` (operator-facing overview) and `DEVELOPMENT.md` (local dev setup) are the primary references.

## What this app is

A **Puavo-specific** Nextcloud app. Lets users with `puavoEduPersonAffiliation=teacher` share files to class groups (`puavoEduGroupType ∈ {year class, teaching group, course group}`) through Nextcloud's native share UI — including from mobile and desktop clients. Implemented as a custom `OCP\GroupInterface` backend that virtualises teacher membership in class groups, plus an `ISearchPlugin` so the picker surfaces the right entries.

Read [`DEVELOPMENT.md`](DEVELOPMENT.md) for the local dev environment (Docker Compose, user_ldap setup, sample fixtures).

Read [`SHARING-INTERNALS.md`](SHARING-INTERNALS.md) for the Nextcloud code paths this app hooks into: how the sharee picker is answered, why the share check is a separate non-extensible mechanism, and the CalDAV asymmetry.

## Local commands

| Command | What it does |
|---|---|
| `composer install` | PHP deps incl. composer-bin-plugin tools |
| `composer psalm` | Strict static analysis. Expect zero errors. |
| `composer cs:check` | php-cs-fixer dry-run |
| `composer cs:fix` | Apply style fixes |
| `make test` | PHPUnit inside the container named by `test_container=` in the `Makefile`; check `docker ps` and override on the command line |
| `make` | Build `build/groupsharemachine.tar.gz` |
| `make sign` | Same but signs via `occ integrity:sign-app` (cert in `~/.nextcloud/certificates/`) |

After any PHP edit, the loop is: `composer cs:fix` → `composer psalm` → `make test`. CI runs all three plus REUSE compliance and a phpunit matrix across MySQL/PgSQL/SQLite × stable31/32/33.

## Conventions

- **Tabs for indentation** (Nextcloud-wide convention; matches `.editorconfig`).
- Prefer **OCP interfaces** (`lib/public/`) over internal NC classes (`lib/private/`). The one exception is `user_ldap`'s `Group_Proxy` / `User_Proxy` / `Access`, which aren't OCP but we depend on them; resolve lazily via `class_exists` + `Server::get` and degrade gracefully when absent.
- This is a **backend-only app**. No frontend toolchain — don't add `package.json`, vite, eslint, etc. The native NC share dialog handles all UI.
- `psalm.xml` runs at error level 1 with `findUnusedCode` off (framework-registered classes look unused). Suppress new false positives in `psalm.xml` rather than adding `@psalm-suppress` everywhere.
- **REUSE-compliant**: every file needs `SPDX-FileCopyrightText` + `SPDX-License-Identifier`, either as a comment header or via `REUSE.toml`. CI runs `fsfe/reuse-action@v5`.

## Migration policy

Migrations are forward-only (`SimpleMigrationStep`), tracked in `oc_migrations`.

- **Pre-release (no users yet):** OK to merge/squash migrations or rewrite them in place. Wipe `oc_migrations` rows + the affected tables on dev environments before re-enabling, so the rewritten migration runs cleanly.
- **Post-release:** never modify a migration that's been shipped. Add a new `VersionXXXXDate...` file instead. There's no rollback path — schema changes must be additive or use safe data backfill.

The MySQL primary-key naming gotcha: explicit names like `gsm_groups_pk` are required because NC's installer enforces a 30-char identifier limit. MySQL still reports the PK as `PRIMARY` (driver convention) — that's fine, the explicit name is what the schema validator checks.

## Data model

Two app-owned tables in NC's main DB; both populated entirely from LDAP by `Service\LdapSync`.

- `groupsharemachine_groups (gid PK, group_type, school_name, school_dn, display_name, abbreviation)` — class groups (with their school for picker labels and scoping). `display_name` exists to be searched: the `gid` of an LDAP group is frozen at the name it had when user_ldap first mapped it, so a group renamed later is unfindable by the name the picker shows. `searchEntriesInner()` matches **`display_name` only** — the name the picker puts on screen — and deliberately neither of the other two name columns:

  - **not `gid`**, because after a year rollover (`4. class` renamed to `5. class`, a new `4. class` created with gid `4. class_2`) the frozen gid is another group's current name, so matching it would offer the wrong cohort. It is used only as a fallback while `display_name` is still NULL, i.e. before the first sync after the upgrade.
  - **not `abbreviation`** (the LDAP `cn`), because it never appears in the label. A hit on it is a result the teacher cannot account for, and it is redundant whenever it overlaps the display name. In puavo it is also often frozen at the group's original year (`cn: 1kl` on a group displayed `5. klasse`), giving it the same staleness as the gid. The column is still synced, so re-enabling the match or showing it in the label needs no migration.
- `groupsharemachine_teachers (uid, school_dn) — composite PK` — one row per (teacher, school) authorisation. Multi-school teachers have multiple rows.

The backend rejects any share where `getSchoolDn($gid)` is missing OR the (uid, school_dn) pair isn't in the teachers table. The picker only surfaces class groups whose school is in the searcher's school set. **Don't add "fallback" logic that lets unscoped rows through** — that would re-introduce cross-school leakage.

## Runtime: the two NC code paths we hook

Nextcloud uses two **distinct** code paths for a group share — the picker (autocomplete in the share dialog) and the create-check (when the share is submitted). We extend both.

### Share-check sequence

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
   │ inGroup(uid, gid)       │  LDAP member ───┤ school of group ∈ schools of uid   │
   └─────────────────────────┘                 └──────────────────┬─────────────────┘
                                                                   │
                                                  any YES → share allowed
```

**Critical Nextcloud subtlety:** `Group::inGroup()` is OR-aggregated across backends that previously claimed `groupExists($gid)`. Our backend's `groupExists()` must return `true` for any gid in `oc_groupsharemachine_groups`, otherwise our `inGroup()` is never consulted and the share is denied even when it should be virtualised. Easy to miss; was a real bug during development.

### Picker sequence

```
   typing "alpha" in share dialog
   ▼
   GET /ocs/.../sharees?search=alpha&shareType[]=1
   ▼
   OC\Collaboration\Collaborators\Search::search()
   │   collects results from every registered plugin for shareType=GROUP
   │
   ├──▶ GroupPlugin (NC core)
   │       searches oc_groups / user_ldap, then filters out groups NOT in
   │       IGroupManager::getUserGroupIds($searcher)  ← excludes class groups
   │       for teachers because our getUserGroups() returns []
   │
   └──▶ TeacherClassSearchPlugin (this app)
           if searcher has any (uid, school_dn) rows:
              SELECT … FROM oc_groupsharemachine_groups
              WHERE display_name LIKE %search%   ← never gid, never abbreviation
                AND school_dn IN (<teacher's schools>)
              contribute each as SearchResultType('groups')
              (NOT filtered by GroupPlugin's user-groups rule — different plugin)
```

**Why `TeacherClassMembership::getUserGroups()` is deliberately empty:** if it returned the class groups, teachers would (a) see those classes in their profile's group list and (b) auto-receive shares directed to those classes (because `IGroupManager::getUserGroupIds()` is what joins to `oc_share.share_with` for inbound shares). Neither is desired — the asymmetry "teachers send to a class but don't receive from it" is intentional, matching the puavo workflow.

## Sync mechanics

`Service\LdapSync` uses paged LDAP searches with combined filters (user_ldap's configured filter `AND` our predicate). Avoid going back to the old "enumerate everyone, attribute-read per user" pattern — it's O(n_users + n_groups) and was the source of multi-minute syncs on real puavo data.

- Filter for groups: `(&<configured>(|(puavoEduGroupType=year class)(puavoEduGroupType=teaching group)(puavoEduGroupType=course group)))`
- Filter for users: `(&<configured>(puavoEduPersonAffiliation=teacher))`
- Per-record school DN resolution: cached within a single sync run (see `$schoolNameCache`).
- Background job runs every 15 minutes via `OCP\BackgroundJob\TimedJob`. Operators trigger immediate sync with `occ groupsharemachine:sync`.
- **A failed search must never prune.** `safeSearch()` returns `null` when the LDAP search throws, as distinct from `[]` for a genuinely empty page — an empty page ends the paging loop and lets `deleteNotIn($kept)` run, so returning `[]` on failure would delete every row and blank the picker for all teachers. On `null` the sync returns early with `complete => false` and skips the prune, leaving the table as the last good run wrote it. Keep that distinction if you touch the paging loop.

## Testing

`make test` runs PHPUnit inside the dev container because mapper tests extend `Test\TestCase` from NC core. From the host alone they can't load the NC bootstrap.

**`make test` wipes the instance it runs against.** Core's `TestCase::tearDownAfterClass()` deletes every directory in the data dir (all user homes, `appdata_*`) and empties `oc_share` / `oc_storages` / `oc_filecache`; our mapper test truncates both app tables. It assumes a throwaway instance. Never point `test_container=` at an instance someone is browsing — the symptom is `NotFoundException: The root directory of the user's files is missing` at the next login, plus an empty share picker. Recovery is in `DEVELOPMENT.md`. The test fakes for `OCA\User_LDAP\Group_Proxy` / `User_Proxy` / `Access` are anonymous classes inside `tests/Unit/Service/LdapSyncTest.php`; extend the existing structure when adding new sync behaviour.

## Diagnostic

`occ groupsharemachine:diagnose <uid> <gid>` is the operator-facing tool for "why can't this teacher share to that class?". It reports each row of the decision chain (user/group existence, school overlap, backend YES/NO, aggregate YES/NO). Keep it up to date when changing the backend's check logic — operators rely on it.

## Things to avoid

- Touching `oc_users`, `oc_accounts`, `oc_groups`, `oc_group_user`, `oc_share`, or `oc_ldap_*` tables from this app. We only read via OCP interfaces; never write.
- Re-adding the frontend toolchain. The custom share-buttons UI (v0.0.1) was deliberately removed — the native share dialog handles everything now and is the whole point of the rewrite.
- Hard-coding paths or per-deployment values. School-specific data (filter cn lists, school IDs) lives in user_ldap config, not in our code.
- Long sleep loops or polling. NC's TimedJob + the explicit `occ` command cover both timeliness and on-demand refresh.
- Disabling NC's `shareapi_only_share_with_group_members` system-wide as a "fix". That restriction is what makes our app meaningful; if you disable it, students can also share to teachers' groups, defeating the privacy boundary.
