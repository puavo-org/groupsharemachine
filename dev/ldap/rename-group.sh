#!/usr/bin/env bash
# SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Rename a seed group in the local docker LDAP, to reproduce the condition
# that broke class-group search in production: Nextcloud freezes a group's
# gid at the displayName it had when user_ldap first mapped it, so after a
# rename the gid and the visible name differ.
#
#   dev/ldap/apply.sh                          # seed data
#   occ groupsharemachine:sync                 # gid gets frozen as "1A"
#   dev/ldap/rename-group.sh 300001 "Klasse 1A"
#   occ groupsharemachine:sync                 # display_name becomes "Klasse 1A"
#
# Then search the share dialog for "Klasse": before the display_name column
# existed this found nothing, because only the stale gid was matched.
#
# Seed groups: 300001 alpha-1a (1A), 300002 alpha-2a (2A),
#              300003 beta-1a (1A), 300004 beta-math (Math (teaching group))

set -euo pipefail

if [ $# -ne 2 ]; then
	echo "usage: $(basename "$0") <puavoId> <new displayName>" >&2
	echo "   e.g. $(basename "$0") 300001 'Klasse 1A'" >&2
	exit 2
fi

PUAVO_ID="$1"
NEW_NAME="$2"

ADMIN_DN="cn=admin,dc=planetexpress,dc=com"
ADMIN_PW="admin"
GROUP_DN="puavoId=${PUAVO_ID},ou=Groups,ou=Puavo,dc=planetexpress,dc=com"

# Fall back to sudo when the current user can't reach the docker socket.
DOCKER="${DOCKER:-docker}"
if ! $DOCKER info >/dev/null 2>&1 && command -v sudo >/dev/null; then
	DOCKER="sudo docker"
fi

LDAP_CONTAINER="${LDAP_CONTAINER:-$($DOCKER ps --format '{{.Names}}' | grep -E '(^|[-_])ldap([-_]|$)' | head -1)}"
if [ -z "$LDAP_CONTAINER" ]; then
	echo "No running LDAP container found. Start it with: docker compose up -d ldap" >&2
	exit 1
fi

echo "==> $LDAP_CONTAINER: $GROUP_DN -> displayName: $NEW_NAME"

$DOCKER exec -i "$LDAP_CONTAINER" ldapmodify -x -H ldap://localhost \
	-D "$ADMIN_DN" -w "$ADMIN_PW" <<EOF
dn: ${GROUP_DN}
changetype: modify
replace: displayName
displayName: ${NEW_NAME}
EOF

echo
echo "==> Done. Re-run 'occ groupsharemachine:sync', then check that the gid"
echo "    still holds the old name while display_name holds the new one:"
echo "      occ groupsharemachine:diagnose <teacher-uid> <old-gid>"
