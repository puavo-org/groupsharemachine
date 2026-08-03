<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Service;

use OCA\GroupShareMachine\Db\ClassGroupMapper;
use OCA\GroupShareMachine\Db\TeacherMapper;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refreshes two local tables from puavo LDAP via user_ldap:
 *  - groupsharemachine_groups: gids whose puavoEduGroupType is in the allow-list
 *  - groupsharemachine_teachers: uids whose puavoEduPersonAffiliation contains 'teacher'
 *
 * Uses paged LDAP search with a combined filter (user_ldap's configured
 * user/group filter AND our attribute predicate). One LDAP roundtrip per page
 * regardless of total user/group count — O(matches / pageSize), not O(total).
 *
 * Couples to user_ldap internals (Group_Proxy / User_Proxy / Access).
 * user_ldap is a core Nextcloud app, but those classes are not OCP, so we
 * resolve them lazily and the sync becomes a no-op when they're unavailable.
 */
class LdapSync {

	public const GROUP_TYPE_ATTR = 'puavoEduGroupType';
	public const SCHOOL_ATTR = 'puavoSchool';
	public const SCHOOL_NAME_ATTR = 'displayName';
	public const AFFILIATION_ATTR = 'puavoEduPersonAffiliation';
	public const TEACHER_AFFILIATION = 'teacher';

	/** @var list<string> */
	public const ALLOWED_GROUP_TYPES = ['year class', 'teaching group', 'course group'];

	private const PAGE_SIZE = 500;

	public function __construct(
		private ClassGroupMapper $groupMapper,
		private TeacherMapper $teacherMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{
	 *   groups: array{seen: int, kept: int, pruned: int},
	 *   teachers: array{seen: int, kept: int, pruned: int},
	 * }
	 */
	public function run(): array {
		return [
			'groups' => $this->syncGroups(),
			'teachers' => $this->syncTeachers(),
		];
	}

	/**
	 * @return array{seen: int, kept: int, pruned: int}
	 */
	private function syncGroups(): array {
		$access = $this->resolveAccess('OCA\\User_LDAP\\Group_Proxy', isUser: false);
		if ($access === null) {
			$this->logger->info('user_ldap Group_Proxy/Access not available, skipping group sync.');
			return $this->emptyStats();
		}

		$filter = $access->combineFilterWithAnd([
			$access->getConnection()->ldapGroupFilter,
			$this->orFilter(self::GROUP_TYPE_ATTR, self::ALLOWED_GROUP_TYPES),
		]);

		$kept = [];
		$seen = 0;
		$offset = 0;
		/** @var array<string, ?string> $schoolNameCache  DN -> resolved displayName (or null) */
		$schoolNameCache = [];
		do {
			$records = $this->safeSearch($access, isUser: false, filter: $filter, offset: $offset);
			foreach ($records as $record) {
				$seen++;
				$dn = $record['dn'][0] ?? null;
				$type = $record[strtolower(self::GROUP_TYPE_ATTR)][0] ?? null;
				if (!is_string($dn) || !is_string($type)) {
					continue;
				}
				$gid = $this->dnToOcName($access, $dn, isUser: false);
				if ($gid === null) {
					continue;
				}
				$schoolDn = $record[strtolower(self::SCHOOL_ATTR)][0] ?? null;
				$schoolName = null;
				if (is_string($schoolDn) && $schoolDn !== '') {
					$schoolName = $schoolNameCache[$schoolDn] ??= $this->resolveSchoolName($access, $schoolDn);
				}
				$this->groupMapper->upsert($gid, $type, $schoolName, is_string($schoolDn) ? $schoolDn : '');
				$kept[] = $gid;
			}
			$offset += self::PAGE_SIZE;
		} while (count($records) === self::PAGE_SIZE);

		$pruned = $this->groupMapper->deleteNotIn($kept);
		return ['seen' => $seen, 'kept' => count($kept), 'pruned' => $pruned];
	}

	private function resolveSchoolName(object $access, string $schoolDn): ?string {
		try {
			$values = $access->readAttribute($schoolDn, self::SCHOOL_NAME_ATTR);
		} catch (Throwable $e) {
			$this->logger->warning("resolveSchoolName failed for {$schoolDn}: " . $e->getMessage());
			return null;
		}
		if (!is_array($values) || $values === []) {
			return null;
		}
		$first = $values[0] ?? null;
		return is_string($first) ? $first : null;
	}

	/**
	 * @return array{seen: int, kept: int, pruned: int}
	 */
	private function syncTeachers(): array {
		$access = $this->resolveAccess('OCA\\User_LDAP\\User_Proxy', isUser: true);
		if ($access === null) {
			$this->logger->info('user_ldap User_Proxy/Access not available, skipping teacher sync.');
			return $this->emptyStats();
		}

		$filter = $access->combineFilterWithAnd([
			$access->getConnection()->ldapUserFilter,
			'(' . self::AFFILIATION_ATTR . '=' . self::escapeLdapValue(self::TEACHER_AFFILIATION) . ')',
		]);

		/** @var list<array{0: string, 1: string}> $kept (uid, school_dn) pairs */
		$kept = [];
		$seen = 0;
		$offset = 0;
		do {
			$records = $this->safeSearch($access, isUser: true, filter: $filter, offset: $offset);
			foreach ($records as $record) {
				$seen++;
				$dn = $record['dn'][0] ?? null;
				if (!is_string($dn)) {
					continue;
				}
				$uid = $this->dnToOcName($access, $dn, isUser: true);
				if ($uid === null) {
					continue;
				}
				$schools = $record[strtolower(self::SCHOOL_ATTR)] ?? null;
				if (!is_array($schools) || $schools === []) {
					// A teacher without an assigned school can't share to any
					// class — record nothing.
					continue;
				}
				foreach ($schools as $schoolDn) {
					if (!is_string($schoolDn) || $schoolDn === '') {
						continue;
					}
					$this->teacherMapper->upsert($uid, $schoolDn);
					$kept[] = [$uid, $schoolDn];
				}
			}
			$offset += self::PAGE_SIZE;
		} while (count($records) === self::PAGE_SIZE);

		$pruned = $this->teacherMapper->deleteNotIn($kept);
		return ['seen' => $seen, 'kept' => count($kept), 'pruned' => $pruned];
	}

	/**
	 * @return array
	 */
	private function safeSearch(object $access, bool $isUser, string $filter, int $offset): array {
		try {
			$attrs = $isUser
				? ['dn', strtolower(self::SCHOOL_ATTR)]
				: ['dn', strtolower(self::GROUP_TYPE_ATTR), strtolower(self::SCHOOL_ATTR)];
			$records = $isUser
				? $access->searchUsers($filter, $attrs, self::PAGE_SIZE, $offset)
				: $access->searchGroups($filter, $attrs, self::PAGE_SIZE, $offset);
			return is_array($records) ? $records : [];
		} catch (Throwable $e) {
			$this->logger->warning(
				($isUser ? 'searchUsers' : 'searchGroups') . " failed at offset {$offset}: " . $e->getMessage(),
			);
			return [];
		}
	}

	private function dnToOcName(object $access, string $dn, bool $isUser): ?string {
		try {
			$name = $access->dn2ocname($dn, null, $isUser);
		} catch (Throwable $e) {
			$this->logger->warning("dn2ocname failed for {$dn}: " . $e->getMessage());
			return null;
		}
		return is_string($name) ? $name : null;
	}

	private function resolveAccess(string $proxyClass, bool $isUser): ?object {
		$proxy = $this->resolveProxy($proxyClass);
		if ($proxy === null) {
			return null;
		}
		try {
			$sample = $isUser
				? $proxy->getUsers('', 1, 0)
				: $proxy->getGroups('', 1, 0);
		} catch (Throwable $e) {
			$this->logger->info("{$proxyClass} sample fetch failed: " . $e->getMessage());
			return null;
		}
		if (!is_array($sample) || $sample === []) {
			return null;
		}
		try {
			$access = $proxy->getLDAPAccess((string)$sample[0]);
		} catch (Throwable $e) {
			$this->logger->info("{$proxyClass}::getLDAPAccess failed: " . $e->getMessage());
			return null;
		}
		return is_object($access) ? $access : null;
	}

	protected function resolveProxy(string $class): ?object {
		if (!class_exists($class)) {
			return null;
		}
		try {
			$instance = Server::get($class);
		} catch (Throwable $e) {
			$this->logger->info("{$class} could not be resolved: " . $e->getMessage());
			return null;
		}
		/** @var object|null $instance */
		return $instance;
	}

	/**
	 * @param list<string> $values
	 */
	private function orFilter(string $attr, array $values): string {
		return '(|' . implode('', array_map(
			static fn (string $v): string => '(' . $attr . '=' . self::escapeLdapValue($v) . ')',
			$values,
		)) . ')';
	}

	private static function escapeLdapValue(string $value): string {
		// RFC 4515: escape ( ) * \ NUL. The space in 'year class' doesn't need
		// escaping in a filter value. LDAP_ESCAPE_FILTER === 2 in ext-ldap;
		// inlined so static analysis doesn't need the extension loaded.
		return ldap_escape($value, '', 2);
	}

	/**
	 * @return array{seen: int, kept: int, pruned: int}
	 */
	private function emptyStats(): array {
		return ['seen' => 0, 'kept' => 0, 'pruned' => 0];
	}
}
