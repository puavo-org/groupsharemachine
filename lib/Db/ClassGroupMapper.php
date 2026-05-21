<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TeacherClassGroup>
 */
class ClassGroupMapper extends QBMapper {

	public const TABLE = 'groupsharemachine_groups';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, TeacherClassGroup::class);
	}

	public function contains(string $gid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from(self::TABLE)
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

	/**
	 * @return list<string>
	 */
	public function listGids(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('gid')->from(self::TABLE);

		$result = $qb->executeQuery();
		$gids = [];
		while (($row = $result->fetch()) !== false) {
			$gids[] = (string)$row['gid'];
		}
		$result->closeCursor();

		return $gids;
	}

	/**
	 * Substring-match search against gids. Used by the collaborator picker
	 * to surface class groups to teachers.
	 *
	 * @return list<string>
	 */
	public function searchGids(string $search, int $limit, int $offset): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('gid')->from(self::TABLE);
		if ($search !== '') {
			$qb->where($qb->expr()->iLike(
				'gid',
				$qb->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%'),
			));
		}
		$qb->orderBy('gid')
			->setMaxResults($limit > 0 ? $limit : 200)
			->setFirstResult($offset);

		$result = $qb->executeQuery();
		$gids = [];
		while (($row = $result->fetch()) !== false) {
			$gids[] = (string)$row['gid'];
		}
		$result->closeCursor();

		return $gids;
	}

	/**
	 * Substring-match search returning gid + school metadata. Used by the
	 * picker plugin to render labels with school disambiguation.
	 *
	 * @return list<array{gid: string, school_name: ?string, school_dn: string}>
	 */
	public function searchEntries(string $search, int $limit, int $offset): array {
		return $this->searchEntriesInner($search, null, $limit, $offset);
	}

	/**
	 * Same as searchEntries, but restricted to class groups whose school_dn
	 * is in $schoolDns. Used by the picker to enforce per-school scoping.
	 *
	 * @param list<string> $schoolDns
	 * @return list<array{gid: string, school_name: ?string, school_dn: string}>
	 */
	public function searchEntriesForSchools(string $search, array $schoolDns, int $limit, int $offset): array {
		if ($schoolDns === []) {
			return [];
		}
		return $this->searchEntriesInner($search, $schoolDns, $limit, $offset);
	}

	/**
	 * @param ?list<string> $schoolDns null = unrestricted
	 * @return list<array{gid: string, school_name: ?string, school_dn: string}>
	 */
	private function searchEntriesInner(string $search, ?array $schoolDns, int $limit, int $offset): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('gid', 'school_name', 'school_dn')->from(self::TABLE);
		if ($search !== '') {
			$qb->where($qb->expr()->iLike(
				'gid',
				$qb->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%'),
			));
		}
		if ($schoolDns !== null) {
			$qb->andWhere($qb->expr()->in(
				'school_dn',
				$qb->createNamedParameter($schoolDns, IQueryBuilder::PARAM_STR_ARRAY),
			));
		}
		$qb->orderBy('gid')
			->setMaxResults($limit > 0 ? $limit : 200)
			->setFirstResult($offset);

		$result = $qb->executeQuery();
		$entries = [];
		while (($row = $result->fetch()) !== false) {
			$school = $row['school_name'] ?? null;
			$entries[] = [
				'gid' => (string)$row['gid'],
				'school_name' => $school === null ? null : (string)$school,
				'school_dn' => (string)($row['school_dn'] ?? ''),
			];
		}
		$result->closeCursor();

		return $entries;
	}

	public function getSchoolName(string $gid): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('school_name')
			->from(self::TABLE)
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));

		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		if ($value === false || $value === null) {
			return null;
		}
		return (string)$value;
	}

	public function getSchoolDn(string $gid): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('school_dn')
			->from(self::TABLE)
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));

		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		if ($value === false || $value === null || $value === '') {
			return null;
		}
		return (string)$value;
	}

	public function upsert(string $gid, string $groupType, ?string $schoolName = null, string $schoolDn = ''): void {
		$qb = $this->db->getQueryBuilder();
		$update = $qb->update(self::TABLE)
			->set('group_type', $qb->createNamedParameter($groupType))
			->set('school_name', $qb->createNamedParameter($schoolName))
			->set('school_dn', $qb->createNamedParameter($schoolDn))
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
		$updated = $update->executeStatement();

		if ($updated === 0) {
			$insert = $this->db->getQueryBuilder();
			$insert->insert(self::TABLE)
				->values([
					'gid' => $insert->createNamedParameter($gid),
					'group_type' => $insert->createNamedParameter($groupType),
					'school_name' => $insert->createNamedParameter($schoolName),
					'school_dn' => $insert->createNamedParameter($schoolDn),
				])
				->executeStatement();
		}
	}

	public function deleteByGid(string $gid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
			->executeStatement();
	}

	/**
	 * Delete rows whose gid is not in $keep. Used by full-sync to prune
	 * groups that have disappeared from LDAP or whose puavoEduGroupType is
	 * no longer in the allow-list.
	 *
	 * @param list<string> $keep
	 */
	public function deleteNotIn(array $keep): int {
		$qb = $this->db->getQueryBuilder();
		$delete = $qb->delete(self::TABLE);
		if ($keep !== []) {
			$delete->where($qb->expr()->notIn(
				'gid',
				$qb->createNamedParameter($keep, IQueryBuilder::PARAM_STR_ARRAY),
			));
		}
		return $delete->executeStatement();
	}
}
