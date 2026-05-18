<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Controller;

use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;

class GroupQueryController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IGroupManager $groupManager,
		private IAccountManager $accountManager,
		private IUserManager $userManager,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function getTeachingGroups(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([]);
		}

		if (!$this->isTeacher()) {
			return new DataResponse([]);
		}

		// Get class groups
		$classGroups = $this->groupManager->search('class_');
		$result = array_map(static fn ($g) => [
			'id' => $g->getGID(),
			'name' => $g->getDisplayName(),
		], $classGroups);

		usort($result, static fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

		return new DataResponse($result);
	}

	private function isTeacher(): bool {
		// Check user profile role (synced from LDAP puavoedupersonaffiliation)
		$user = $this->userManager->get($this->userId);
		if ($user !== null) {
			$account = $this->accountManager->getAccount($user);
			$role = $account->getProperty(IAccountManager::PROPERTY_ROLE)->getValue();
			if ($role === 'teacher') {
				return true;
			}
		}

		// Fallback: check if user belongs to any teachers_ group
		$userGroups = $this->groupManager->getUserGroupIds($this->userId);
		foreach ($userGroups as $gid) {
			if (str_starts_with($gid, 'teachers_')) {
				return true;
			}
		}

		return false;
	}
}
