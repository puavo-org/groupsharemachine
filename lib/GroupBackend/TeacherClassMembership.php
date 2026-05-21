<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\GroupBackend;

use OCA\GroupShareMachine\Db\ClassGroupMapper;
use OCA\GroupShareMachine\Db\TeacherMapper;
use OCP\GroupInterface;

/**
 * Virtual group backend. Makes teachers (per the LDAP-synced teachers
 * table) appear as members of every group recorded in the class-groups
 * table for the purposes of Share20\Manager::groupCreateChecks() — which
 * calls IGroup::inGroup($sharedBy) and accepts YES from any backend.
 *
 * Deliberately returns [] from getUserGroups() so teachers don't see those
 * groups in their group list and don't auto-receive shares directed to them.
 */
class TeacherClassMembership implements GroupInterface {

	public function __construct(
		private ClassGroupMapper $groupMapper,
		private TeacherMapper $teacherMapper,
	) {
	}

	public function inGroup($uid, $gid): bool {
		$schoolDn = $this->groupMapper->getSchoolDn($gid);
		if ($schoolDn === null) {
			return false;
		}
		return $this->teacherMapper->belongsToSchool($uid, $schoolDn);
	}

	public function getUserGroups($uid): array {
		return [];
	}

	public function getGroups(string $search = '', int $limit = -1, int $offset = 0): array {
		return [];
	}

	public function groupExists($gid): bool {
		return $this->groupMapper->contains($gid);
	}

	public function usersInGroup($gid, $search = '', $limit = -1, $offset = 0): array {
		return [];
	}

	public function implementsActions($actions): bool {
		return false;
	}
}
