#!/usr/bin/env bash
# SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Inject the puavo schema + seed data into the local osixia/openldap
# container started by nextcloud-docker-dev. Idempotent: re-running just
# logs "already exists" for entries that are already there.

set -euo pipefail

LDAP_CONTAINER="${LDAP_CONTAINER:-master_ldap_1}"
ADMIN_DN="cn=admin,dc=planetexpress,dc=com"
ADMIN_PW="admin"
CONFIG_DN="cn=admin,cn=config"
CONFIG_PW="config"

DEV_DIR="$(cd "$(dirname "$0")" && pwd)"

if ! docker ps --format '{{.Names}}' | grep -qx "$LDAP_CONTAINER"; then
	echo "Container $LDAP_CONTAINER is not running."
	echo "Start it from nextcloud-docker-dev with: docker-compose up -d ldap"
	exit 1
fi

ldap_add() {
	local description="$1"
	local user="$2"
	local pw="$3"
	local file="$4"
	echo
	echo "==> $description ($file)"
	# -c continues on errors (so we tolerate "already exists")
	docker exec -i "$LDAP_CONTAINER" ldapadd -x -H ldap://localhost -D "$user" -w "$pw" -c < "$DEV_DIR/$file" || true
}

ldap_add "Adding puavo schema to cn=config"    "$CONFIG_DN" "$CONFIG_PW" "schema.ldif"
ldap_add "Loading puavo-flavoured test data"   "$ADMIN_DN"  "$ADMIN_PW"  "seed.ldif"

echo
echo "==> Done. Verify with:"
echo "    docker exec -i $LDAP_CONTAINER ldapsearch -x -D '$ADMIN_DN' -w '$ADMIN_PW' \\"
echo "      -b 'ou=Puavo,dc=planetexpress,dc=com' '(objectclass=puavoEduPerson)' uid puavoEduPersonAffiliation puavoSchool"
