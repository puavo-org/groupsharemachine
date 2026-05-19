<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getGid()
 * @method void setGid(string $gid)
 * @method string getGroupType()
 * @method void setGroupType(string $groupType)
 * @psalm-suppress PropertyNotSetInConstructor
 */
class TeacherClassGroup extends Entity {

	protected string $gid = '';
	protected string $groupType = '';

	public function __construct() {
		$this->addType('gid', 'string');
		$this->addType('groupType', 'string');
	}
}
