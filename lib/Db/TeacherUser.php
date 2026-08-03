<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUid()
 * @method void setUid(string $uid)
 * @psalm-suppress PropertyNotSetInConstructor
 */
class TeacherUser extends Entity {

	protected string $uid = '';

	public function __construct() {
		$this->addType('uid', 'string');
	}
}
