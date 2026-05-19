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

	public function upsert(string $gid, string $groupType): void {
		$qb = $this->db->getQueryBuilder();
		$updated = $qb->update(self::TABLE)
			->set('group_type', $qb->createNamedParameter($groupType))
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
			->executeStatement();

		if ($updated === 0) {
			$insert = $this->db->getQueryBuilder();
			$insert->insert(self::TABLE)
				->values([
					'gid' => $insert->createNamedParameter($gid),
					'group_type' => $insert->createNamedParameter($groupType),
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
