<!--
SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
SPDX-License-Identifier: CC0-1.0
-->

# Local development with Docker

This guide walks through running Group Share Machine against a local Nextcloud server using the upstream [`nextcloud-docker-dev`](https://github.com/juliusknorr/nextcloud-docker-dev) setup. It targets `stable33` by default but the same workflow works for `stable31` and `stable32`.

## Prerequisites

- Docker + Docker Compose (`docker compose`). Commands below are written with `sudo`; drop it if your user is in the `docker` group.
- PHP 8.1+ and Composer 2.x (only for `composer psalm` / `composer cs:fix` / `composer rector` on the host — the dev container has its own PHP for tests via `make test`)
- Git
- ~10 GB free disk for the Nextcloud image, server checkout, and database volumes

The app is backend-only — no Node, npm, or frontend toolchain is needed. The native Nextcloud share dialog handles all UI.

## 1. Clone and bootstrap `nextcloud-docker-dev`

```bash
cd ~/dev/nextcloud
git clone https://github.com/juliusknorr/nextcloud-docker-dev
cd nextcloud-docker-dev
./bootstrap.sh
```

`bootstrap.sh` clones the Nextcloud server **master** branch into `workspace/server/`, copies `example.env` to `.env`, and creates the proxy + database volumes. Run it once.

> **Check container names, don't guess them.** With `COMPOSE_PROJECT_NAME=master` the containers are named `master-stable33-1`, `master-database-mysql-1`, and so on, but a container Compose recreates in place keeps whatever name it already had — so a long-lived stack can carry a mix of separators. Run `docker ps --format '{{.Names}}'` and use what you actually see; the `Makefile`'s `docker_container=` / `test_container=` can be overridden on the command line when they don't match.

Add `nextcloud.local` and any `stableNN.local` hostnames you plan to use to `/etc/hosts`:

```
127.0.0.1  nextcloud.local stable31.local stable32.local stable33.local
```

### Clone the stable branches you want to use

`bootstrap.sh` does **not** clone the stable branches — its validation loop only checks if they already exist. The `stableNN` compose services mount `workspace/stableNN/` as `/var/www/html`, so if the directory is empty the container exits with:

```
🚨 Could not find a valid Nextcloud source in /var/www/html
```

Clone each version you want before starting its container. If the container has already run once against an empty dir, the entrypoint will have created some scaffolding dirs owned by root that you need to remove first:

```bash
cd ~/dev/nextcloud/nextcloud-docker-dev
sudo docker compose stop stable33   # release the mount
sudo rm -rf workspace/stable33      # drop root-owned scaffolding
git clone --depth=1 --branch stable33 \
    https://github.com/nextcloud/server.git workspace/stable33
git -C workspace/stable33 submodule update --init      # 3rdparty etc.
```

Repeat for `stable31` / `stable32` to test the app's lower NC versions. Drop `--depth=1` if you want full git history.

## 2. Mount this repo as an extra app

`nextcloud-docker-dev` mounts `${ADDITIONAL_APPS_PATH:-./data/apps-extra}` (host) at `/var/www/html/apps-shared` (container). A **symlink** under that path will not work — symlinks store path text, and a symlink pointing to `/home/you/...` is a dangling link from inside the container. `occ app:enable` falls back to the appstore and fails with:

```
Could not download app groupsharemachine, it was not found on the appstore
```

The clean fix is a real bind mount via a Compose override — no extra apps-extra directory needed. Leave `ADDITIONAL_APPS_PATH` unset in `.env` (it defaults to the empty `./data/apps-extra/` shipped with `nextcloud-docker-dev`) and create `~/dev/nextcloud/nextcloud-docker-dev/docker-compose.override.yml`:

```yaml
services:
  stable31:
    volumes:
      - /home/<you>/dev/nextcloud/groupsharemachine:/var/www/html/apps-shared/groupsharemachine
  stable32:
    volumes:
      - /home/<you>/dev/nextcloud/groupsharemachine:/var/www/html/apps-shared/groupsharemachine
  stable33:
    volumes:
      - /home/<you>/dev/nextcloud/groupsharemachine:/var/www/html/apps-shared/groupsharemachine
  nextcloud:
    volumes:
      - /home/<you>/dev/nextcloud/groupsharemachine:/var/www/html/apps-shared/groupsharemachine
```

Compose merges the `volumes:` list with the base file's, so this adds a single extra bind mount on top of the existing `apps-shared` mount. `ADDITIONAL_APPS_PATH` in `.env` can stay pointed at an empty directory (or be unset).

After editing the override, force-recreate the container — `sudo docker compose up -d stableNN` alone reports "up-to-date" and won't pick up the new mount:

```bash
cd ~/dev/nextcloud/nextcloud-docker-dev
sudo docker compose up -d --force-recreate stable33
sudo docker compose exec stable33 ls /var/www/html/apps-shared/groupsharemachine   # should show repo contents
```

> The `Makefile` references a container name and `~/dev/nextcloud/nextcloud-docker-dev/data/shared` — both come from this same setup (`COMPOSE_PROJECT_NAME=master` in `.env`). Keep this layout and `make sign` works unchanged, apart from passing the container name if it differs.

## 3. Start a Nextcloud version

The compose file ships separate services per stable branch. After cloning `workspace/stable33` (see section 1), bring it up:

```bash
cd ~/dev/nextcloud/nextcloud-docker-dev
sudo docker compose up -d stable33
```

For the other supported versions (each needs its own `workspace/stableNN` clone first):

```bash
sudo docker compose up -d stable31   # min supported
sudo docker compose up -d stable32
```

Each service is reachable at `http://stableNN.local`. Default login is `admin` / `admin`. First boot autoinstalls Nextcloud — watch progress with `sudo docker compose logs -f stable33`.

## 4. Enable the app

Once the server is up, enable the app via `occ`:

```bash
sudo docker compose exec -u www-data stable33 php occ app:enable groupsharemachine
```

If the app does not appear in `app:list`, check the symlink target is readable from inside the container:

```bash
sudo docker compose exec stable33 ls /var/www/html/apps-shared/groupsharemachine
```

A useful shell alias to avoid typing the prefix:

```bash
# add to ~/.bashrc or ~/.zshrc
ncocc() { (cd ~/dev/nextcloud/nextcloud-docker-dev && sudo docker compose exec -u www-data "${1:-stable33}" php occ "${@:2}"); }
# usage: ncocc stable33 app:list
```

## 5. Configure sharing and run the sync

The app does nothing useful until two things are set up:

```bash
# 1. Restrict group sharing to in-group members (the standard NC restriction).
#    Teachers will bypass this via the custom group backend; everyone else stays constrained.
ncocc stable33 config:app:set core shareapi_only_share_with_group_members --value=yes

# 2. Populate the local class-group table from LDAP (otherwise it's empty until the background job fires).
ncocc stable33 groupsharemachine:sync
```

`groupsharemachine:sync` walks LDAP via user_ldap's proxies and refreshes two tables:
- `oc_groupsharemachine_groups` — gids whose `puavoEduGroupType` is in the allow-list
- `oc_groupsharemachine_teachers` — uids whose multi-valued `puavoEduPersonAffiliation` contains `teacher`

Output looks like:
```
Groups:   seen=482 kept=37 pruned=0
Teachers: seen=210 kept=18 pruned=0
```

A `TimedJob` runs the same sync every 15 minutes.

## 6. Test fixtures (with LDAP)

The app relies on real LDAP-synced users and groups — there's no pure-Nextcloud fallback for teacher detection or group typing. The repo ships a self-contained docker LDAP test bed under `dev/ldap/` that injects a puavo-flavoured multi-school dataset into the `osixia/openldap` container that nextcloud-docker-dev provides.

### Files

| File | Purpose |
|---|---|
| `dev/ldap/schema.ldif` | Adds minimal puavo schema (`puavoEduPerson`, `puavoEduGroup` aux objectclasses; `puavoId`, `puavoEduGroupType`, `puavoEduPersonAffiliation`, `puavoSchool` attributes) under cn=config. |
| `dev/ldap/seed.ldif` | Two schools (Alpha, Beta), five users (alice/bob/charlie/diana/erik) and four class groups, designed to exercise single-school, multi-school, and cross-school-denial cases. |
| `dev/ldap/apply.sh` | `ldapadd`s schema + seed into the running LDAP container. Idempotent — re-running just logs "already exists" for entries already there. |
| `dev/ldap/use-docker.sh` | Reconfigures NC's `user_ldap` to bind to the docker LDAP (`ldap:389`, anonymous bind allowed for admin). |
| `dev/ldap/rename-group.sh` | Changes a seed group's `displayName`, to reproduce the stale-gid condition that broke class-group search — see [Reproducing the renamed-group bug](#reproducing-the-renamed-group-bug). |

All three find their container via `docker ps` rather than a hardcoded name, and re-invoke docker through `sudo` if the socket isn't reachable directly. Override with `LDAP_CONTAINER=` / `NC_CONTAINER=`, or point at another server with `NC_SERVICE=stable34`.

### One-time setup

```bash
# 1. Bring up the LDAP service alongside stable33
cd ~/dev/nextcloud/nextcloud-docker-dev
sudo docker compose up -d ldap

# 2. Load the puavo schema + seed data
cd ~/dev/nextcloud/groupsharemachine
bash dev/ldap/apply.sh

# 3. Point local NC at the docker LDAP
bash dev/ldap/use-docker.sh

# 4. Delete the pre-seeded local NC users that collide with our LDAP uids
#    (otherwise NC's Database backend wins authentication first)
cd ~/dev/nextcloud/nextcloud-docker-dev
for u in alice bob charlie diana erik john jane; do
  sudo docker compose exec -T -u www-data stable33 php occ user:delete "$u" 2>/dev/null || true
done
```

### Test accounts (passwords match the uid)

**Log in with the email address, not the bare uid.** `nextcloud-docker-dev` seeds its own
Database accounts called `alice` and `bob`, and Nextcloud consults the Database backend
before user_ldap — so `alice` / `alice` signs you in as the *local* account, which has no
teacher rows and therefore sees no class groups in the picker. The symptom is a share
dialog that finds nothing, with everything else apparently configured correctly. Check
with `occ user:info <login>`: `backend: Database` means you are on the wrong account.

| Login | NC uid | Role | School(s) | Use case |
|---|---|---|---|---|
| `alice@example.test` | `100001` | teacher | Alpha | single-school teacher |
| `bob@example.test` | `100002` | teacher | Alpha + Beta | multi-school teacher |
| `charlie@example.test` | `100003` | teacher | Beta | single-school teacher |
| `diana@example.test` | `100004` | student | Alpha | non-teacher, real-LDAP-member of `1A` |
| `erik@example.test` | `100005` | student | Beta | non-teacher |

Email login works because `dev/ldap/use-docker.sh` sets `ldapLoginFilter` to
`(&(objectClass=puavoEduPerson)(|(uid=%uid)(mail=%uid)))`. On an instance configured
before that change, apply it with:

```bash
sudo docker exec -u www-data master-stable33-1 php occ ldap:set-config s01 \
    ldapLoginFilter '(&(objectClass=puavoEduPerson)(|(uid=%uid)(mail=%uid)))'
sudo docker exec -u www-data master-stable33-1 php occ ldap:set-config s01 ldapLoginFilterMode 1
```

The class groups `1A` (Alpha) and `1A_2` (Beta — collision suffix added by user_ldap because both have `displayName: 1A`) intentionally share a display name to exercise the picker's school-disambiguation labels.

### Reproducing the renamed-group bug

Nextcloud has no stable internal id for groups: the gid is whatever `ldapGroupDisplayName` held when user_ldap first mapped the group, and it never changes afterwards. Rename the group upstream and Nextcloud shows the new name while every lookup still uses the old gid — which is why searching the share dialog for the name on screen used to return nothing.

```bash
bash dev/ldap/apply.sh                          # seed: group 300001 is "1A"
ncocc stable33 groupsharemachine:sync           # gid frozen as "1A"

bash dev/ldap/rename-group.sh 300001 'Klasse 1A'
ncocc stable33 groupsharemachine:sync           # display_name now "Klasse 1A"
```

Verify both halves are stored:

```bash
sudo docker exec -t master-database-mysql-1 mysql -uroot -pnextcloud stable33 -e \
  "SELECT gid, display_name, abbreviation FROM oc_groupsharemachine_groups;"
```

Expect `gid = 1A` alongside `display_name = Klasse 1A`. Then log in as `alice@example.test` and type "Klasse" in a share dialog — the group must appear. Before the fix, "Klasse" found nothing at all, because only the frozen gid was searched. Searching the abbreviation (`alpha-1a`) must **not** find it: the column is synced but not searched, because it never appears in the picker label.

Note that typing `1A` still finds this group, since `1A` is a substring of `Klasse 1A` — the match is on the display name, not on the gid. To watch the gid genuinely drop out of the search, rename to a name that shares nothing with the old one:

```bash
bash dev/ldap/rename-group.sh 300001 'Klasse Eins'
ncocc stable33 groupsharemachine:sync
```

Now `1A` must return nothing for `alice@example.test` even though the row's gid is still literally `1A`, while `Eins` finds it. That is the behaviour the year-rollover case depends on: when `4. class` is renamed to `5. class` and a new `4. class` appears under gid `4. class_2`, a search for `4. class` has to return the new cohort only, never the group whose stale gid happens to spell it.

### Expected matrix

With the seed exactly as shipped (no rename applied):

```
User      Type "1A" in share dialog → picker shows
─────────────────────────────────────────────────────
alice     1A (Alpha School)
bob       1A (Alpha School)  +  1A (Beta School)
charlie                          1A (Beta School)
diana     1A                  (real LDAP membership; app contributes nothing)
```

After `rename-group.sh 300001 'Klasse 1A'` the Alpha rows are labelled `Klasse 1A (Alpha School)`, because the picker labels with the group's current display name. Logins are the email addresses — see [Test accounts](#test-accounts-passwords-match-the-uid).

### Inspect

```bash
sudo docker exec -t master-database-mysql-1 mysql -uroot -pnextcloud stable33 -e "
  SELECT * FROM oc_groupsharemachine_groups;
  SELECT * FROM oc_groupsharemachine_teachers;
"
ncocc stable33 groupsharemachine:diagnose 100001 1A
```


## 7. Xdebug

The dev images ship Xdebug with `PHP_XDEBUG_MODE=develop` by default. To switch to step debugging:

```bash
# in nextcloud-docker-dev/.env
PHP_XDEBUG_MODE=debug,develop
```

Restart the container. Xdebug connects back to the host on port 9003. In VS Code, add a "PHP: Listen for Xdebug" launch config with:

```json
{
    "name": "Listen for Xdebug",
    "type": "php",
    "request": "launch",
    "port": 9003,
    "pathMappings": {
        "/var/www/html/apps-shared/groupsharemachine": "${workspaceFolder}"
    }
}
```

## 8. PHP quality checks

Run from this repo on the host (uses `composer-bin-plugin` to install isolated tool versions under `vendor-bin/`):

```bash
composer install               # installs tools on post-install
composer cs:check              # php-cs-fixer dry run
composer cs:fix                # apply fixes
composer psalm                 # strict static analysis  (or: make psalm)
composer rector                # apply rector rules, then cs:fix
make test                      # phpunit inside the stable33 container
```

> **`make test` is destructive to the target instance.** It deletes every user home directory and empties `oc_share` / `oc_storages` / `oc_filecache` plus both app tables — see [the troubleshooting note](#never-run-the-tests-against-an-instance-you-are-using). Use a container you are not browsing in.

`composer psalm` runs at the configured `errorLevel` — expect zero errors on this branch.

`make test` shells into `master-stable33-1` and runs `vendor/bin/phpunit -c tests/phpunit.xml`. The mapper tests need NC's bootstrap (and a real DB), so they only work inside the container — running phpunit from the host won't load `Test\TestCase`. To target a different stable version, override `test_container=`:

```bash
make test test_container=master-stable32-1
```

## 9. Building a release tarball

```bash
make            # build/groupsharemachine.tar.gz, unsigned
make sign       # signed package (needs certs in ~/.nextcloud/certificates/)
```

`make sign` shells into the container named by `docker_container=` in the `Makefile` to run `occ integrity:sign-app`. If your compose project is namespaced differently (e.g. you renamed the directory or use `docker compose --project-name`), the container name will differ — check `docker ps` and pass the right name in:

```bash
make sign docker_container=master-stable33-1
```

## Troubleshooting

**App not visible in `app:list`** — the bind mount must point to a real directory. See section 2 about `docker-compose.override.yml`.

**`Class OCA\GroupShareMachine\... not found`** — most often the app is **not enabled in the container you are testing against**. `AppManager::loadApps()` registers an app's PSR-4 path only for enabled apps, and `vendor/bin/phpunit` does not load this repo's own `vendor/autoload.php`, so an unenabled app has no autoloader at all. Fix with `occ app:enable groupsharemachine` in that container (add `--force` when the container's Nextcloud is newer than `max-version` in `appinfo/info.xml`). Failing that, `composer install` was not run in this repo, or `appinfo/info.xml` declares a namespace that doesn't match the PSR-4 autoload entry — check `composer.json` and run `composer dump-autoload`.

**Teacher sees no class groups in the share picker** — Run `occ groupsharemachine:diagnose <uid> <gid>`. It tells you whether the user is in the teachers table, whether the group is in the groups table, and what the backend would do. If either table is empty, run `occ groupsharemachine:sync`; if it still misses entries, check that user_ldap's Base User / Group Tree and Group-Member association are set, and that `puavoEduPersonAffiliation` / `puavoEduGroupType` exist on the corresponding LDAP entries.

**Share fails with "Sharing is only allowed within your own groups"** — Confirm `shareapi_only_share_with_group_members` is `yes`, then run `groupsharemachine:diagnose` for the (user, group) pair. `virtualised by this app: NO` means the backend is correctly choosing not to bypass the restriction (user not a teacher, or group not a class). `virtualised by this app: YES` but the share still fails means the share check uses a different code path — file a bug.

**`Permission denied` writing to `data/shared/sign`** — the dev container runs as `www-data` (uid 33). The `make sign` recipe `chmod -R a+rwX`'s the sign dir before invoking `occ`; if you ran it once as root the leftover files may need `sudo rm -rf data/shared/sign` to clean up.

**`OCP\Files\NotFoundException: The root directory of the user's files is missing`** — almost always means **`make test` has been run against the instance you are browsing**. See [Never run the tests against an instance you are using](#never-run-the-tests-against-an-instance-you-are-using) for why.

The user's home exists on disk but has no `files/` subdirectory, so `OC_Helper::getStorageInfo()` bails out and every page of the Files app 500s — the login path sees `data/<uid>/` already there and never re-runs skeleton setup. Recreate the directory and re-scan, in the container:

```bash
sudo docker exec master-stable33-1 bash -c '
    mkdir -p /var/www/html/data/<uid>/files &&
    cp -rn /skeleton/. /var/www/html/data/<uid>/files/ &&
    chown -R www-data:www-data /var/www/html/data/<uid>'
sudo docker exec -u www-data master-stable33-1 php occ files:scan <uid>
sudo docker exec -u www-data master-stable33-1 php occ groupsharemachine:sync
```

`<uid>` is the Nextcloud uid, not the login name — for LDAP accounts that is the puavoId (`100001`), not `alice`. The sync is needed because the same test run empties the app tables.

Copy from `/skeleton` (what `skeletondirectory` points at in this compose setup), not `core/skeleton`, which only holds `welcome.txt`. To find every account in this state rather than guessing from the error page, list the home storages that have no `files` node:

```sql
SELECT s.id FROM oc_storages s
 WHERE s.id LIKE 'home::%'
   AND NOT EXISTS (SELECT 1 FROM oc_filecache f
                    WHERE f.storage = s.numeric_id AND f.path = 'files');
```

Accounts that have never logged in have no storage row at all and provision normally, so they need no repair.

**Never run the tests against an instance you are using** — the mapper tests extend `Test\TestCase` from Nextcloud core, whose `tearDownAfterClass()` cleans up after itself on the assumption that it owns a throwaway instance (`/var/www/html/tests/lib/TestCase.php`):

```php
self::tearDownAfterClassCleanShares($queryBuilder);     // DELETE FROM oc_share
self::tearDownAfterClassCleanStorages($queryBuilder);   // DELETE FROM oc_storages
self::tearDownAfterClassCleanFileCache($queryBuilder);  // DELETE FROM oc_filecache
self::tearDownAfterClassCleanStrayDataFiles($dataDir);  // rm -rf everything in data/
```

`tearDownAfterClassCleanStrayDataFiles()` keeps only `nextcloud.log`, `audit.log`, `owncloud.db` and `.ocdata`, and recursively deletes every other directory in the data directory — all user homes and `appdata_*` included. On top of that `ClassGroupMapperTest` truncates both app tables in `setUp()`/`tearDown()`.

So one `make test` run leaves the instance with no user home directories, an empty filecache and empty app tables. Nothing warns you; the damage only shows up at the next login as the 500 above, and as a share picker that finds no groups.

Point `test_container=` at a stable container you do not browse in, and keep manual testing on another one:

```bash
make test test_container=master-stable32-1   # tests here
# browse http://stable33.local               # manual testing there
```

**Container name mismatch** — names are derived from `COMPOSE_PROJECT_NAME=master` in `.env` (e.g. `master-stable33-1`), but a container recreated in place keeps its original name, so a stack can end up with mixed separators. Run `docker ps --format '{{.Names}}'` to see what you actually have, and override `docker_container=` / `test_container=` on the `make` command line.

**`Could not find a valid Nextcloud source in /var/www/html`** — `workspace/stableNN/` is empty. See section 1 — `bootstrap.sh` doesn't clone the stable branches, you have to do it yourself.

**`Could not download app groupsharemachine, it was not found on the appstore`** — Nextcloud can't see the app on disk and is falling back to the appstore. Almost always caused by mounting via a host-path symlink instead of a real bind mount. See section 2 — use the `docker-compose.override.yml` approach.

**Browser caches the old JS bundle** — Nextcloud appends a cache buster based on the app version, but in dev with the same version string you may need a hard refresh (Ctrl+Shift+R) or to bump the version in `appinfo/info.xml`.

## Useful references

- nextcloud-docker-dev docs: https://juliusknorr.github.io/nextcloud-docker-dev/
- Default users and ports: https://juliusknorr.github.io/nextcloud-docker-dev/basics/overview/
- Nextcloud app development: https://docs.nextcloud.com/server/latest/developer_manual/
- OCS share API: https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-share-api.html
