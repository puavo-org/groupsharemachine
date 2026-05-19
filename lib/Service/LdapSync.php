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
 * Walks LDAP via user_ldap's proxies and refreshes two local tables:
 *  - groupsharemachine_groups: gids whose puavoEduGroupType is in the allow-list
 *  - groupsharemachine_teachers: uids whose puavoEduPersonAffiliation includes 'teacher'
 *
 * The teacher attribute is multi-valued in LDAP, but Nextcloud's role
 * account property is single-valued and user_ldap loses any non-primary
 * value during sync. Reading the LDAP attribute directly fixes that.
 *
 * user_ldap's Group_Proxy / User_Proxy / Access are not OCP — we resolve
 * them lazily and the sync becomes a no-op if user_ldap is unavailable.
 */
class LdapSync {

	public const GROUP_TYPE_ATTR = 'puavoEduGroupType';
	public const AFFILIATION_ATTR = 'puavoEduPersonAffiliation';
	public const TEACHER_AFFILIATION = 'teacher';

	/** @var list<string> */
	public const ALLOWED_GROUP_TYPES = ['year class', 'teaching_group'];

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
		$proxy = $this->resolveProxy('OCA\\User_LDAP\\Group_Proxy');
		if ($proxy === null) {
			$this->logger->info('user_ldap Group_Proxy not available, skipping group sync.');
			return ['seen' => 0, 'kept' => 0, 'pruned' => 0];
		}

		$kept = [];
		$seen = 0;
		$offset = 0;
		do {
			/** @var list<string> $gids */
			$gids = $proxy->getGroups('', self::PAGE_SIZE, $offset);
			foreach ($gids as $gid) {
				$seen++;
				$type = $this->readAttributeForId($proxy, $gid, self::GROUP_TYPE_ATTR, self::ALLOWED_GROUP_TYPES);
				if ($type !== null) {
					$this->groupMapper->upsert($gid, $type);
					$kept[] = $gid;
				} else {
					$this->groupMapper->deleteByGid($gid);
				}
			}
			$offset += self::PAGE_SIZE;
		} while (count($gids) === self::PAGE_SIZE);

		$pruned = $this->groupMapper->deleteNotIn($kept);
		return ['seen' => $seen, 'kept' => count($kept), 'pruned' => $pruned];
	}

	/**
	 * @return array{seen: int, kept: int, pruned: int}
	 */
	private function syncTeachers(): array {
		$proxy = $this->resolveProxy('OCA\\User_LDAP\\User_Proxy');
		if ($proxy === null) {
			$this->logger->info('user_ldap User_Proxy not available, skipping teacher sync.');
			return ['seen' => 0, 'kept' => 0, 'pruned' => 0];
		}

		$kept = [];
		$seen = 0;
		$offset = 0;
		do {
			/** @var list<string> $uids */
			$uids = $proxy->getUsers('', self::PAGE_SIZE, $offset);
			foreach ($uids as $uid) {
				$seen++;
				$match = $this->readAttributeForId($proxy, $uid, self::AFFILIATION_ATTR, [self::TEACHER_AFFILIATION]);
				if ($match !== null) {
					$this->teacherMapper->upsert($uid);
					$kept[] = $uid;
				}
			}
			$offset += self::PAGE_SIZE;
		} while (count($uids) === self::PAGE_SIZE);

		$pruned = $this->teacherMapper->deleteNotIn($kept);
		return ['seen' => $seen, 'kept' => count($kept), 'pruned' => $pruned];
	}

	/**
	 * Read a (potentially multi-valued) LDAP attribute and return the first
	 * value that appears in $allowed, or null if none match.
	 *
	 * @param list<string> $allowed
	 */
	private function readAttributeForId(object $proxy, string $id, string $attr, array $allowed): ?string {
		try {
			$access = $proxy->getLDAPAccess($id);
			if ($access === null) {
				return null;
			}
			$dn = $this->resolveDn($access, $id, $attr === self::AFFILIATION_ATTR);
			if ($dn === false) {
				return null;
			}
			$values = $access->readAttribute($dn, $attr);
		} catch (Throwable $e) {
			$this->logger->warning("Failed to read {$attr} for {$id}: " . $e->getMessage());
			return null;
		}

		if (!is_array($values) || $values === []) {
			return null;
		}

		foreach ($values as $value) {
			$value = (string)$value;
			if (in_array($value, $allowed, true)) {
				return $value;
			}
		}
		return null;
	}

	private function resolveDn(object $access, string $id, bool $isUser): false|string {
		return $isUser
			? $access->username2dn($id)
			: $access->groupname2dn($id);
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
}
