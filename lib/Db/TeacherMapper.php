<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TeacherUser>
 *
 * Per (uid, school_dn) pair: a single LDAP teacher may be in multiple
 * schools and therefore have multiple rows here.
 */
class TeacherMapper extends QBMapper {

	public const TABLE = 'groupsharemachine_teachers';

	/**
	 * Pairs per DELETE. Two bound parameters each, so 500 keeps a statement
	 * at 1000 placeholders — well inside every driver's limit, and small
	 * enough that the generated SQL stays readable in a slow-query log.
	 */
	private const DELETE_CHUNK_SIZE = 500;

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
	 * Every (uid, school_dn) pair currently stored.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	public function listPairs(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid', 'school_dn')->from(self::TABLE);

		$result = $qb->executeQuery();
		$pairs = [];
		while (($row = $result->fetch()) !== false) {
			$pairs[] = [(string)$row['uid'], (string)$row['school_dn']];
		}
		$result->closeCursor();
		return $pairs;
	}

	/**
	 * Delete every (uid, school_dn) pair not in $keep. Each entry must be
	 * an array of exactly two strings [uid, schoolDn].
	 *
	 * An empty $keep deletes every row; callers must be sure the LDAP walk
	 * completed before passing it (see LdapSync's `complete` flag).
	 *
	 * @param list<array{0: string, 1: string}> $keep
	 */
	public function deleteNotIn(array $keep): int {
		if ($keep === []) {
			$qb = $this->db->getQueryBuilder();
			return $qb->delete(self::TABLE)->executeStatement();
		}

		// Diff here rather than negating the keep-set in SQL. The old
		// predicate was one "NOT ((uid=? AND school_dn=?) OR ...)" covering
		// every teacher, which grows two placeholders per authorisation; the
		// stale set is almost always empty, so now the usual sync deletes
		// nothing and issues no statement.
		$keepIndex = [];
		foreach ($keep as $pair) {
			$keepIndex[$pair[0] . "\0" . $pair[1]] = true;
		}
		$stale = array_values(array_filter(
			$this->listPairs(),
			static fn (array $pair): bool => !isset($keepIndex[$pair[0] . "\0" . $pair[1]]),
		));

		$deleted = 0;
		foreach (array_chunk($stale, self::DELETE_CHUNK_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$conditions = array_map(
				static fn (array $pair): ICompositeExpression => $qb->expr()->andX(
					$qb->expr()->eq('uid', $qb->createNamedParameter($pair[0])),
					$qb->expr()->eq('school_dn', $qb->createNamedParameter($pair[1])),
				),
				$chunk,
			);
			$deleted += $qb->delete(self::TABLE)
				->where($qb->expr()->orX(...$conditions))
				->executeStatement();
		}
		return $deleted;
	}
}
