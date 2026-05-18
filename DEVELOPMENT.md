<!--
SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
SPDX-License-Identifier: CC0-1.0
-->

# Local development with Docker

This guide walks through running Group Share Machine against a local Nextcloud server using the upstream [`nextcloud-docker-dev`](https://github.com/juliusknorr/nextcloud-docker-dev) setup. It targets `stable33` by default but the same workflow works for `stable31` and `stable32`.

## Prerequisites

- Docker + Docker Compose v2
- Node.js 20.x (see `.nvmrc`), npm 10.x
- PHP 8.1+ and Composer 2.x (only for `composer psalm` / `composer cs:fix` / `composer rector` — the container has its own PHP)
- Git
- ~10 GB free disk for the Nextcloud image, server checkout, and database volumes

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

## 5. Build the frontend

From this repo:

```bash
npm ci
npm run watch    # rebuilds on save
```

`npm run watch` writes the bundle to `js/`, which is mounted into the container via the symlink — refresh the browser to see changes. For one-off builds use `npm run build`.

The Vite config (`vite.config.ts`) emits `groupsharemachine-groupsharemachine.mjs`, which is loaded by `lib/AppInfo/Application.php` on `LoadAdditionalScriptsEvent`.

## 6. Create teacher and class test fixtures

The app's business logic keys off two group prefixes (`teachers_` and `class_`) and an LDAP-synced `role` account property. For a pure-Nextcloud test (no LDAP):

```bash
ncocc stable33 group:add teachers_schoolA
ncocc stable33 group:add class_1a
ncocc stable33 group:add class_1b
ncocc stable33 group:add class_2a

ncocc stable33 user:add --password-from-env teacher1 <<< 'password123'
ncocc stable33 user:add --password-from-env student1 <<< 'password123'

ncocc stable33 group:adduser teachers_schoolA teacher1
ncocc stable33 group:adduser class_1a student1
```

Log in as `teacher1` and open the Files sharing sidebar on any file — you should see one-click share buttons for `class_1a`, `class_1b`, `class_2a`. Log in as `student1` and the panel should be hidden entirely.

### Testing the LDAP role path

If you want to exercise the `PROPERTY_ROLE === 'teacher'` branch in `GroupQueryController::isTeacher()`, start the `ldap` service (`docker compose up -d ldap`), bind it via the LDAP app, and set `puavoedupersonaffiliation: teacher` on the user. With no LDAP, group-prefix membership is the only way the user is detected as a teacher.

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
composer psalm                 # strict static analysis
composer rector                # apply rector rules, then cs:fix
composer test:unit             # phpunit (when tests exist)
```

`composer psalm` runs at `errorLevel="1"` with `findUnusedCode` enabled — expect zero issues on this branch.

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

**App not visible in `app:list`** — the symlink target must be absolute and readable. Symlinks pointing into `$HOME` are fine; relative symlinks are not. Verify with `docker compose exec stable33 readlink -f /var/www/html/apps-shared/groupsharemachine`.

**`Class OCA\GroupShareMachine\... not found`** — `composer install` was not run in this repo, or `appinfo/info.xml` declares a namespace that doesn't match the PSR-4 autoload entry. Check `composer.json` and run `composer dump-autoload`.

**Vite bundle not loaded in the browser** — `Application.php` registers `groupsharemachine-groupsharemachine`; if you renamed the entry in `vite.config.ts`, update the `Util::addScript()` call to match.

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
