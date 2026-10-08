#!/usr/bin/env bash
# SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Inject the puavo schema + seed data into the local osixia/openldap
# container started by nextcloud-docker-dev. Idempotent: re-running just
# logs "already exists" for entries that are already there.

set -euo pipefail

ADMIN_DN="cn=admin,dc=planetexpress,dc=com"
ADMIN_PW="admin"
CONFIG_DN="cn=admin,cn=config"
CONFIG_PW="config"

DEV_DIR="$(cd "$(dirname "$0")" && pwd)"

# Fall back to sudo when the current user can't reach the docker socket.
DOCKER="${DOCKER:-docker}"
if ! $DOCKER info >/dev/null 2>&1 && command -v sudo >/dev/null; then
	DOCKER="sudo docker"
fi

# Container names depend on which Compose version created them, so look the
# name up instead of hardcoding a separator. Override with LDAP_CONTAINER=.
LDAP_CONTAINER="${LDAP_CONTAINER:-$($DOCKER ps --format '{{.Names}}' | grep -E '(^|[-_])ldap([-_]|$)' | head -1)}"

if [ -z "$LDAP_CONTAINER" ] || ! $DOCKER ps --format '{{.Names}}' | grep -qx "$LDAP_CONTAINER"; then
	echo "No running LDAP container found${LDAP_CONTAINER:+ (looked for '$LDAP_CONTAINER')}."
	echo "Start it from nextcloud-docker-dev with: docker compose up -d ldap"
	exit 1
fi

echo "==> Using LDAP container: $LDAP_CONTAINER"

ldap_add() {
	local description="$1"
	local user="$2"
	local pw="$3"
	local file="$4"
	echo
	echo "==> $description ($file)"
	# -c continues on errors (so we tolerate "already exists")
	$DOCKER exec -i "$LDAP_CONTAINER" ldapadd -x -H ldap://localhost -D "$user" -w "$pw" -c < "$DEV_DIR/$file" || true
}

ldap_add "Adding puavo schema to cn=config"    "$CONFIG_DN" "$CONFIG_PW" "schema.ldif"
ldap_add "Loading puavo-flavoured test data"   "$ADMIN_DN"  "$ADMIN_PW"  "seed.ldif"

echo
echo "==> Done. Verify with:"
echo "    $DOCKER exec -i $LDAP_CONTAINER ldapsearch -x -D '$ADMIN_DN' -w '$ADMIN_PW' \\"
echo "      -b 'ou=Puavo,dc=planetexpress,dc=com' '(objectclass=puavoEduPerson)' uid puavoEduPersonAffiliation puavoSchool"
