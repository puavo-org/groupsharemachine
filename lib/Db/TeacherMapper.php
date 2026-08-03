<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TeacherUser>
 *
 * Per (uid, school_dn) pair: a single LDAP teacher may be in multiple
 * schools and therefore have multiple rows here.
 */
class TeacherMapper extends QBMapper {

	public const TABLE = 'groupsharemachine_teachers';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, TeacherUser::class);
	}

	/**
	 * "Is this uid recognised as a teacher in any school?"
	 */
	public function contains(string $uid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from(self::TABLE)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

	/**
	 * "Is this uid a teacher in this specific school?"
	 */
	public function belongsToSchool(string $uid, string $schoolDn): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from(self::TABLE)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('school_dn', $qb->createNamedParameter($schoolDn)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

	/**
	 * @return list<string> school DNs this uid is a teacher in
	 */
	public function schoolsForTeacher(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('school_dn')
			->from(self::TABLE)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

		$result = $qb->executeQuery();
		$schools = [];
		while (($row = $result->fetch()) !== false) {
			$schools[] = (string)$row['school_dn'];
		}
		$result->closeCursor();
		return $schools;
	}

	public function upsert(string $uid, string $schoolDn): void {
		$qb = $this->db->getQueryBuilder();
		$updated = $qb->update(self::TABLE)
			->set('uid', $qb->createNamedParameter($uid))   // touch — no-op other columns
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('school_dn', $qb->createNamedParameter($schoolDn)))
			->executeStatement();

		if ($updated === 0) {
			$insert = $this->db->getQueryBuilder();
			$insert->insert(self::TABLE)
				->values([
					'uid' => $insert->createNamedParameter($uid),
					'school_dn' => $insert->createNamedParameter($schoolDn),
				])
				->executeStatement();
		}
	}

	/**
	 * Delete every (uid, school_dn) pair not in $keep. Each entry must be
	 * an array of exactly two strings [uid, schoolDn].
	 *
	 * @param list<array{0: string, 1: string}> $keep
	 */
	public function deleteNotIn(array $keep): int {
		// Build the predicate "(uid, school_dn) NOT IN ((...), (...), ...)"
		// portably: many drivers don't accept row-tuple IN, so OR a flat
		// list of "(uid = ? AND school_dn = ?)" exclusions.
		$qb = $this->db->getQueryBuilder();
		$delete = $qb->delete(self::TABLE);
		if ($keep === []) {
			return $delete->executeStatement();
		}

		$keepExpr = array_map(
			fn (array $pair): string => '('
				. $qb->expr()->eq('uid', $qb->createNamedParameter($pair[0]))
				. ' AND '
				. $qb->expr()->eq('school_dn', $qb->createNamedParameter($pair[1]))
				. ')',
			$keep,
		);
		$delete->where('NOT (' . implode(' OR ', $keepExpr) . ')');
		return $delete->executeStatement();
	}
}
