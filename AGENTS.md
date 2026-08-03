<!--
SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
SPDX-License-Identifier: CC0-1.0
-->

# Agent guide

This file briefs AI coding assistants on the project's conventions, runtime model, and gotchas. If you're a human, the contents may still be useful — but `README.md` (operator-facing overview) and `DEVELOPMENT.md` (local dev setup) are the primary references.

## What this app is

A **Puavo-specific** Nextcloud app. Lets users with `puavoEduPersonAffiliation=teacher` share files to class groups (`puavoEduGroupType ∈ {year class, teaching group, course group}`) through Nextcloud's native share UI — including from mobile and desktop clients. Implemented as a custom `OCP\GroupInterface` backend that virtualises teacher membership in class groups, plus an `ISearchPlugin` so the picker surfaces the right entries.

Read [`DEVELOPMENT.md`](DEVELOPMENT.md) for the local dev environment (Docker Compose, user_ldap setup, sample fixtures).

## Local commands

| Command | What it does |
|---|---|
| `composer install` | PHP deps incl. composer-bin-plugin tools |
| `composer psalm` | Strict static analysis. Expect zero errors. |
| `composer cs:check` | php-cs-fixer dry-run |
| `composer cs:fix` | Apply style fixes |
| `make test` | PHPUnit inside the `master_stable33_1` container (override `test_container=` for stable31/32) |
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

- `groupsharemachine_groups (gid PK, group_type, school_name, school_dn)` — class groups (with their school for picker labels and scoping).
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
              WHERE gid LIKE %search% AND school_dn IN (<teacher's schools>)
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

## Testing

`make test` runs PHPUnit inside the dev container because mapper tests extend `Test\TestCase` from NC core. From the host alone they can't load the NC bootstrap. The test fakes for `OCA\User_LDAP\Group_Proxy` / `User_Proxy` / `Access` are anonymous classes inside `tests/Unit/Service/LdapSyncTest.php`; extend the existing structure when adding new sync behaviour.

## Diagnostic

`occ groupsharemachine:diagnose <uid> <gid>` is the operator-facing tool for "why can't this teacher share to that class?". It reports each row of the decision chain (user/group existence, school overlap, backend YES/NO, aggregate YES/NO). Keep it up to date when changing the backend's check logic — operators rely on it.

## Things to avoid

- Touching `oc_users`, `oc_accounts`, `oc_groups`, `oc_group_user`, `oc_share`, or `oc_ldap_*` tables from this app. We only read via OCP interfaces; never write.
- Re-adding the frontend toolchain. The custom share-buttons UI (v0.0.1) was deliberately removed — the native share dialog handles everything now and is the whole point of the rewrite.
- Hard-coding paths or per-deployment values. School-specific data (filter cn lists, school IDs) lives in user_ldap config, not in our code.
- Long sleep loops or polling. NC's TimedJob + the explicit `occ` command cover both timeliness and on-demand refresh.
- Disabling NC's `shareapi_only_share_with_group_members` system-wide as a "fix". That restriction is what makes our app meaningful; if you disable it, students can also share to teachers' groups, defeating the privacy boundary.
