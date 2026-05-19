<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TeacherUser>
 */
class TeacherMapper extends QBMapper {

	public const TABLE = 'groupsharemachine_teachers';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, TeacherUser::class);
	}

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

	public function upsert(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$updated = $qb->update(self::TABLE)
			->set('uid', $qb->createNamedParameter($uid))
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->executeStatement();

		if ($updated === 0) {
			$insert = $this->db->getQueryBuilder();
			$insert->insert(self::TABLE)
				->values(['uid' => $insert->createNamedParameter($uid)])
				->executeStatement();
		}
	}

	/**
	 * @param list<string> $keep
	 */
	public function deleteNotIn(array $keep): int {
		$qb = $this->db->getQueryBuilder();
		$delete = $qb->delete(self::TABLE);
		if ($keep !== []) {
			$delete->where($qb->expr()->notIn(
				'uid',
				$qb->createNamedParameter($keep, IQueryBuilder::PARAM_STR_ARRAY),
			));
		}
		return $delete->executeStatement();
	}
}
