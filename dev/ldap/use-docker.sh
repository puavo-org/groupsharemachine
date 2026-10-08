#!/usr/bin/env bash
# SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Point local Nextcloud's user_ldap at the local docker LDAP container
# (osixia/openldap with our puavo-flavoured test data). The opposite
# switch — back to Opinsys staging LDAP — is use-staging.sh.

set -euo pipefail

PREFIX="${LDAP_PREFIX:-s01}"
NC_SERVICE="${NC_SERVICE:-stable33}"

# Fall back to sudo when the current user can't reach the docker socket.
DOCKER="${DOCKER:-docker}"
if ! $DOCKER info >/dev/null 2>&1 && command -v sudo >/dev/null; then
	DOCKER="sudo docker"
fi

# Container names depend on which Compose version created them, so look the
# name up instead of hardcoding a separator. Override with NC_CONTAINER=, or
# pick a different server with NC_SERVICE=stable34.
NC_CONTAINER="${NC_CONTAINER:-$($DOCKER ps --format '{{.Names}}' | grep -E "(^|[-_])${NC_SERVICE}([-_]|$)" | head -1)}"

if [ -z "$NC_CONTAINER" ]; then
	echo "No running container found for service '$NC_SERVICE'."
	echo "Start it with: docker compose up -d $NC_SERVICE"
	exit 1
fi

echo "==> Using Nextcloud container: $NC_CONTAINER"

run_occ() {
	$DOCKER exec -u www-data "$NC_CONTAINER" php /var/www/html/occ "$@"
}

set_cfg() {
	echo "  $1 = $2"
	run_occ ldap:set-config "$PREFIX" "$1" "$2" >/dev/null
}

echo "==> Switching $PREFIX to docker LDAP"

set_cfg ldapHost                    'ldap'
set_cfg ldapPort                    '389'
set_cfg ldapTLS                     '0'
set_cfg ldapBackupPort              ''

set_cfg ldapAgentName               'cn=admin,dc=planetexpress,dc=com'
set_cfg ldapAgentPassword           'admin'

set_cfg ldapBase                    'ou=Puavo,dc=planetexpress,dc=com'
set_cfg ldapBaseUsers               'ou=People,ou=Puavo,dc=planetexpress,dc=com'
set_cfg ldapBaseGroups              'ou=Groups,ou=Puavo,dc=planetexpress,dc=com'

set_cfg ldapUserFilter              '(objectClass=puavoEduPerson)'
set_cfg ldapUserFilterObjectclass   'puavoEduPerson'
set_cfg ldapUserFilterMode          '1'
# Mail is accepted as a login name on purpose: the nextcloud-docker-dev stack
# ships Database accounts named alice and bob, and the Database backend is
# consulted before user_ldap, so logging in as plain "alice" lands on the local
# account instead of the LDAP teacher. Logging in as alice@example.test is
# unambiguous. Raw filter mode (1) because the assisted builder would overwrite
# this from the checkboxes below.
set_cfg ldapLoginFilter             '(&(objectClass=puavoEduPerson)(|(uid=%uid)(mail=%uid)))'
set_cfg ldapLoginFilterMode         '1'
set_cfg ldapLoginFilterUsername     '1'
set_cfg ldapLoginFilterEmail        '1'
set_cfg ldapAttributesForUserSearch 'displayName;uid'

set_cfg ldapGroupFilter             '(&(objectClass=posixGroup)(puavoEduGroupType=*))'
set_cfg ldapGroupFilterObjectclass  'posixGroup'
set_cfg ldapGroupFilterMode         '1'
set_cfg ldapGroupMemberAssocAttr    'memberUid'
set_cfg ldapAttributesForGroupSearch 'displayName;cn'

set_cfg ldapUserDisplayName         'displayName'
set_cfg ldapGroupDisplayName        'displayName'
set_cfg ldapEmailAttribute          'mail'
set_cfg ldapAttributeRole           'puavoEduPersonAffiliation'

set_cfg ldapExpertUsernameAttr      'puavoId'
set_cfg ldapExpertUUIDUserAttr      'puavoId'
set_cfg ldapExpertUUIDGroupAttr     'puavoId'
set_cfg homeFolderNamingRule        'attr:puavoid'

set_cfg ldapConfigurationActive     '1'

echo
echo "==> Test connection"
run_occ ldap:test-config "$PREFIX"

echo
echo "==> Clearing brute-force counter for the local proxy IP"
run_occ security:bruteforce:reset 192.168.21.4 >/dev/null || true

echo
echo "==> Clear app tables + re-sync to populate from the new source"
run_occ groupsharemachine:sync

echo
echo "==> Done. Try logging in as alice / alice (teacher in Alpha only)."
echo "    Other accounts: bob/bob (both schools), charlie/charlie (Beta),"
echo "                    diana/diana (student in Alpha), erik/erik (student in Beta)."
