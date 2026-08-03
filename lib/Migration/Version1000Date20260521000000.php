<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema for groupsharemachine v1.0.0.
 *
 *  - groupsharemachine_groups (gid PK):
 *      class groups whose puavoEduGroupType is in the allow-list, with their
 *      school name (for picker display) and school DN (for scoping).
 *
 *  - groupsharemachine_teachers ((uid, school_dn) composite PK):
 *      one row per (teacher, school) authorisation pair. Teachers in multiple
 *      schools have multiple rows; the per-school check is enforced by the
 *      group backend.
 */
class Version1000Date20260521000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('groupsharemachine_groups')) {
			$table = $schema->createTable('groupsharemachine_groups');
			$table->addColumn('gid', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('group_type', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('school_name', Types::STRING, [
				'notnull' => false,
				'length' => 128,
			]);
			$table->addColumn('school_dn', Types::STRING, [
				'notnull' => false,
				'length' => 256,
			]);
			$table->setPrimaryKey(['gid'], 'gsm_groups_pk');
			$table->addIndex(['group_type'], 'gsm_groups_type_idx');
		}

		if (!$schema->hasTable('groupsharemachine_teachers')) {
			$table = $schema->createTable('groupsharemachine_teachers');
			$table->addColumn('uid', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('school_dn', Types::STRING, [
				'notnull' => true,
				'length' => 256,
			]);
			$table->setPrimaryKey(['uid', 'school_dn'], 'gsm_teachers_pk');
		}

		return $schema;
	}
}
