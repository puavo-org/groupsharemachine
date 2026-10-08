<!--
SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
SPDX-License-Identifier: CC0-1.0
-->

# How Nextcloud sharing works, and where this app hooks in

Notes on the Nextcloud code paths behind the share dialog, written while
debugging this app. The short version: **the picker and the share check are
two different mechanisms**, only one of them is extensible, and calendars use
a third that ignores both.

Line references are from **Nextcloud 33** (`stable33`). They drift between
releases; the class and method names are the stable part.

## 1. Who answers the share dialog

Typing in the sharee field issues one OCS request per keystroke (debounced):

```
GET /ocs/v2.php/apps/files_sharing/api/v1/sharees?search=klasse&itemType=file
  └─ ShareesAPIController::search()                    apps/files_sharing/lib/Controller/
       │  enforces sharing.minSearchStringLength (default 0)
       └─ OC\Collaboration\Collaborators\Search::search()
            ├─ UserPlugin          core
            ├─ GroupPlugin         core
            ├─ MailPlugin / RemotePlugin / LookupPlugin
            └─ TeacherClassSearchPlugin                 this app
```

Every plugin writes into one shared `ISearchResult`, which keeps two ranked
buckets — `exact` and `wide`.

### Where group results come from

Core's `GroupPlugin` calls `IGroupManager::search()`, which fans out to every
registered group backend:

| Backend | Storage | How it searches |
|---|---|---|
| `Database` | SQL `oc_groups` | `LIKE` on gid / displayname |
| `user_ldap` `Group_LDAP::getGroups()` | **live LDAP** | `ldapGroupFilter` AND `getFilterPartForGroupSearch()`, i.e. the attributes in `ldapAttributesForGroupSearch` (or the display-name attribute). Results cached per `getGroups-<search>-<limit>-<offset>` for `ldapCacheTTL`, default 600 s |
| `TeacherClassMembership` (this app) | — | returns `[]` on purpose |

So core *does* query LDAP, and LDAP *would* match on display name. But when
`shareapi_only_share_with_group_members` is on, `GroupPlugin` then throws most
of it away:

```php
// lib/private/Collaboration/Collaborators/GroupPlugin.php:61-70
$userGroups = $this->groupManager->getUserGroups($this->userSession->getUser());
$groupIds = array_intersect($groupIds, $userGroups);
```

Class groups are not in a teacher's group list — this app keeps them out
deliberately, so teachers neither see them in their profile nor auto-receive
shares aimed at them. Consequence: **core's own search can never surface a
class group to a teacher.** That is what `TeacherClassSearchPlugin` exists for,
and it answers from the local `oc_groupsharemachine_groups` snapshot, never
from LDAP.

## 2. The share check — not extensible

There is no plugin interface for "may X share with Y". The rule is hardcoded:

```php
// lib/private/Share20/Manager.php:517-526  (groupCreateChecks)
$sharedWith = $this->groupManager->get($share->getSharedWith());
if (is_null($sharedWith) || in_array(...) || !$sharedWith->inGroup($sharedBy)) {
    throw new \Exception('Sharing is only allowed within your own groups');
}
```

`IGroup::inGroup()` is OR-aggregated across all backends that claimed
`groupExists($gid)`, so a backend can answer "yes, this user is a member" for
sharing purposes without the group appearing anywhere else. That is the second
half of this app:

```php
// lib/AppInfo/Application.php  (boot)
$groupManager->addBackend($backend);                 // makes the share pass
$collaboratorSearch->registerPlugin([...]);          // makes the group findable
```

**Both halves are required.** The plugin alone shows a group the share check
then rejects; the backend alone accepts a share for a group nobody can find.

## 3. Calendars (CalDAV) — a third mechanism

Calendar sharing does not go through `Share20\Manager` at all, and it never
calls `inGroup()`. It intersects the user's group list instead:

```php
// apps/dav/lib/DAV/GroupPrincipalBackend.php:173-180 (searchPrincipals)
// apps/dav/lib/DAV/GroupPrincipalBackend.php:252-259 (findByUri)
if ($this->shareManager->shareWithGroupMembersOnly()) {
    $restrictGroups = $this->groupManager->getUserGroupIds($user);
}
...
if ($restrictGroups !== false && !\in_array($name, $restrictGroups, true)) {
    return null;
}
```

So virtualised membership is invisible to CalDAV: the class group is filtered
out of the calendar share picker (`searchPrincipals`) and the share itself is
refused (`findByUri`). Two further notes:

- `shareWithGroupMembersOnlyExcludeGroupsList()` — the official escape hatch,
  honoured by files sharing (`Manager.php:461`, `:522`) — has **no references
  anywhere in `apps/dav`**. Exempting a group fixes files and not calendars.
- `GroupPrincipalBackend` is constructed inside the `dav` app, so an app
  container cannot decorate or replace it. Fixing this properly means an
  upstream change: have DAV ask `inGroup()`/`isInGroup()` instead of
  intersecting `getUserGroupIds()`. Behaviour is identical for ordinary
  backends and starts working for virtual ones.

## 4. What a picker plugin can actually do

`ISearchPlugin` is one method — `search($search, $limit, $offset, ISearchResult $result)` —
and `ISearchResult` allows more than appending:

| Method | Effect |
|---|---|
| `addResultSet($type, $wide, $exact)` | add hits to either bucket |
| `hasResult($type, $id)` | skip what another plugin already returned |
| `removeCollaboratorResult($type, $id)` | delete another plugin's hit |
| `unsetResult($type)` | drop an entire result type |
| `markExactIdMatch($type)` | force the "exact match" treatment |

Plugins run in registration order, so a late plugin can filter earlier ones,
but not the reverse. Core's own plugins use the same interface — an app plugin
is not second class.

Other sharing extension points, for orientation: `OCP\Share\IShareProvider`
plus the provider factory (invent a new share type and own its storage and
rules), and `IRegistrationContext::registerPublicShareTemplateProvider()` (the
only sharing-related entry in `IRegistrationContext`, for public link pages).

## 5. Gotchas worth remembering

- **Group gids are frozen.** Nextcloud has no stable internal id for groups.
  For LDAP groups the gid is whatever `ldapGroupDisplayName` held when
  `user_ldap` first mapped the group, and a later rename updates only the
  display name — `oc_ldap_group_mapping` keeps the original. Anything that
  searches or stores gids must not assume the gid equals the visible name;
  this app stores `display_name` and `abbreviation` alongside it for exactly
  that reason.
- **`groupExists()` gates `inGroup()`.** A backend whose `groupExists()`
  returns `false` never gets asked about membership, and the share is denied
  with no clue as to why.
- **The label and the query can disagree.** The picker renders
  `IGroup::getDisplayName()` (live, via the LDAP backend's cache) while a
  plugin may match against its own stored copy — a group can therefore be
  displayed correctly and still be unfindable.
- **`sharing.minSearchStringLength`** silently suppresses short queries before
  any plugin runs.
- **user_ldap caches** group searches, memberships and display names for
  `ldapCacheTTL` (default 600 s), which makes search behaviour look
  intermittent while testing. Saving any LDAP config clears the cache
  (`Connection::saveConfiguration()` → `clearCache()`).
