<!--
SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
SPDX-License-Identifier: CC0-1.0
-->

# Local development with Docker

This guide walks through running Group Share Machine against a local Nextcloud server using the upstream [`nextcloud-docker-dev`](https://github.com/juliusknorr/nextcloud-docker-dev) setup. It targets `stable33` by default but the same workflow works for `stable31` and `stable32`.

## Prerequisites

- Docker + Docker Compose (v1 or v2; the repo's `Makefile` defaults to v1 underscore-style container names)
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

> The `docker compose` v2 plugin and the legacy `docker-compose` v1 binary use different container-name separators (`master-stable33-1` vs `master_stable33_1`). The repo's `Makefile` defaults to the v1 underscore form (`master_nextcloud_1`). If you have v2, override with `make sign docker_container=master-stable33-1`.

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
docker-compose stop stable33                           # release the mount
sudo rm -rf workspace/stable33                         # drop root-owned scaffolding
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

After editing the override, force-recreate the container — `docker-compose up -d stableNN` alone reports "up-to-date" and won't pick up the new mount:

```bash
cd ~/dev/nextcloud/nextcloud-docker-dev
docker-compose up -d --force-recreate stable33
docker-compose exec stable33 ls /var/www/html/apps-shared/groupsharemachine   # should show repo contents
```

> The `Makefile` references `master_nextcloud_1` and `~/dev/nextcloud/nextcloud-docker-dev/data/shared` — those names come from this same setup (`COMPOSE_PROJECT_NAME=master` in `.env`, Compose v1 underscore separator). Keep this layout and `make sign` works unchanged.

## 3. Start a Nextcloud version

The compose file ships separate services per stable branch. After cloning `workspace/stable33` (see section 1), bring it up:

```bash
cd ~/dev/nextcloud/nextcloud-docker-dev
docker-compose up -d stable33
```

For the other supported versions (each needs its own `workspace/stableNN` clone first):

```bash
docker-compose up -d stable31   # min supported
docker-compose up -d stable32
```

Each service is reachable at `http://stableNN.local`. Default login is `admin` / `admin`. First boot autoinstalls Nextcloud — watch progress with `docker-compose logs -f stable33`.

## 4. Enable the app

Once the server is up, enable the app via `occ`:

```bash
docker-compose exec -u www-data stable33 php occ app:enable groupsharemachine
```

If the app does not appear in `app:list`, check the symlink target is readable from inside the container:

```bash
docker-compose exec stable33 ls /var/www/html/apps-shared/groupsharemachine
```

A useful shell alias to avoid typing the prefix:

```bash
# add to ~/.bashrc or ~/.zshrc
ncocc() { (cd ~/dev/nextcloud/nextcloud-docker-dev && docker-compose exec -u www-data "${1:-stable33}" php occ "${@:2}"); }
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
| `dev/ldap/apply.sh` | `ldapadd`s schema + seed into the running `master_ldap_1` container. Idempotent — re-running just logs "already exists" for entries already there. |
| `dev/ldap/use-docker.sh` | Reconfigures NC's `user_ldap` to bind to the docker LDAP (`ldap:389`, anonymous bind allowed for admin). |

### One-time setup

```bash
# 1. Bring up the LDAP service alongside stable33
cd ~/dev/nextcloud/nextcloud-docker-dev
docker-compose up -d ldap

# 2. Load the puavo schema + seed data
cd ~/dev/nextcloud/groupsharemachine
bash dev/ldap/apply.sh

# 3. Point local NC at the docker LDAP
bash dev/ldap/use-docker.sh

# 4. Delete the pre-seeded local NC users that collide with our LDAP uids
#    (otherwise NC's Database backend wins authentication first)
cd ~/dev/nextcloud/nextcloud-docker-dev
for u in alice bob charlie diana erik john jane; do
  docker-compose exec -T -u www-data stable33 php occ user:delete "$u" 2>/dev/null || true
done
```

### Test accounts (passwords match the uid)

| Login | NC uid | Role | School(s) | Use case |
|---|---|---|---|---|
| `alice` | `100001` | teacher | Alpha | single-school teacher |
| `bob` | `100002` | teacher | Alpha + Beta | multi-school teacher |
| `charlie` | `100003` | teacher | Beta | single-school teacher |
| `diana` | `100004` | student | Alpha | non-teacher, real-LDAP-member of `1A` |
| `erik` | `100005` | student | Beta | non-teacher |

The class groups `1A` (Alpha) and `1A_2` (Beta — collision suffix added by user_ldap because both have `displayName: 1A`) intentionally share a display name to exercise the picker's school-disambiguation labels.

### Expected matrix

```
User      Type "1A" in share dialog → picker shows
─────────────────────────────────────────────────────
alice     1A (Alpha School)
bob       1A (Alpha School)  +  1A (Beta School)
charlie                          1A (Beta School)
diana     1A                  (real LDAP membership; app contributes nothing)
```

### Inspect

```bash
docker exec -t master_database-mysql_1 mysql -uroot -pnextcloud stable33 -e "
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

`composer psalm` runs at the configured `errorLevel` — expect zero errors on this branch.

`make test` shells into `master_stable33_1` and runs `vendor/bin/phpunit -c tests/phpunit.xml`. The mapper tests need NC's bootstrap (and a real DB), so they only work inside the container — running phpunit from the host won't load `Test\TestCase`. To target a different stable version, override `test_container=`:

```bash
make test test_container=master_stable32_1
```

## 9. Building a release tarball

```bash
make            # build/groupsharemachine.tar.gz, unsigned
make sign       # signed package (needs certs in ~/.nextcloud/certificates/)
```

`make sign` shells into the running container named `master_nextcloud_1` to run `occ integrity:sign-app`. If your compose project is namespaced differently (e.g. you renamed the directory or use `docker compose --project-name`), the container name will differ — pass the right name in:

```bash
make sign docker_container=nextcloud-docker-dev_stable33_1
```

## Troubleshooting

**App not visible in `app:list`** — the bind mount must point to a real directory. See section 2 about `docker-compose.override.yml`.

**`Class OCA\GroupShareMachine\... not found`** — `composer install` was not run in this repo, or `appinfo/info.xml` declares a namespace that doesn't match the PSR-4 autoload entry. Check `composer.json` and run `composer dump-autoload`.

**Teacher sees no class groups in the share picker** — Run `occ groupsharemachine:diagnose <uid> <gid>`. It tells you whether the user is in the teachers table, whether the group is in the groups table, and what the backend would do. If either table is empty, run `occ groupsharemachine:sync`; if it still misses entries, check that user_ldap's Base User / Group Tree and Group-Member association are set, and that `puavoEduPersonAffiliation` / `puavoEduGroupType` exist on the corresponding LDAP entries.

**Share fails with "Sharing is only allowed within your own groups"** — Confirm `shareapi_only_share_with_group_members` is `yes`, then run `groupsharemachine:diagnose` for the (user, group) pair. `virtualised by this app: NO` means the backend is correctly choosing not to bypass the restriction (user not a teacher, or group not a class). `virtualised by this app: YES` but the share still fails means the share check uses a different code path — file a bug.

**`Permission denied` writing to `data/shared/sign`** — the dev container runs as `www-data` (uid 33). The `make sign` recipe `chmod -R a+rwX`'s the sign dir before invoking `occ`; if you ran it once as root the leftover files may need `sudo rm -rf data/shared/sign` to clean up.

**Container name mismatch** — Compose v1 uses underscores (`master_nextcloud_1`, matches `COMPOSE_PROJECT_NAME=master` in `.env`); v2 uses hyphens (`master-nextcloud-1`). The `Makefile` defaults to the v1 form. Run `docker ps --format '{{.Names}}'` to see what you actually have, and override `docker_container=` on the `make sign` command line.

**`Could not find a valid Nextcloud source in /var/www/html`** — `workspace/stableNN/` is empty. See section 1 — `bootstrap.sh` doesn't clone the stable branches, you have to do it yourself.

**`Could not download app groupsharemachine, it was not found on the appstore`** — Nextcloud can't see the app on disk and is falling back to the appstore. Almost always caused by mounting via a host-path symlink instead of a real bind mount. See section 2 — use the `docker-compose.override.yml` approach.

**Browser caches the old JS bundle** — Nextcloud appends a cache buster based on the app version, but in dev with the same version string you may need a hard refresh (Ctrl+Shift+R) or to bump the version in `appinfo/info.xml`.

## Useful references

- nextcloud-docker-dev docs: https://juliusknorr.github.io/nextcloud-docker-dev/
- Default users and ports: https://juliusknorr.github.io/nextcloud-docker-dev/basics/overview/
- Nextcloud app development: https://docs.nextcloud.com/server/latest/developer_manual/
- OCS share API: https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-share-api.html
