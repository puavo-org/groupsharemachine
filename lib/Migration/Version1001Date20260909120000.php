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
 * Adds the two searchable name columns to groupsharemachine_groups.
 *
 * The picker used to match the search term against the gid only. For LDAP
 * groups the gid is frozen at the value ldapGroupDisplayName had when the
 * group was first mapped, so a group renamed in puavo keeps the old gid
 * while Nextcloud shows the new name — and searching for the name you see
 * matched nothing.
 *
 *  - display_name: current ldapGroupDisplayName value, i.e. what the picker
 *    labels the group with.
 *  - abbreviation: the group's cn, which in puavo is the short slug ("nct")
 *    admins and teachers use to refer to a school's groups.
 *
 * Both are refreshed on every sync and left NULL until the next one runs.
 */
class Version1001Date20260909120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('groupsharemachine_groups')) {
			return null;
		}

		$table = $schema->getTable('groupsharemachine_groups');
		$changed = false;

		if (!$table->hasColumn('display_name')) {
			$table->addColumn('display_name', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('abbreviation')) {
			$table->addColumn('abbreviation', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
