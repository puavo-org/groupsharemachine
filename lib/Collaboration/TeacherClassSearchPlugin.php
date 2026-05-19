<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Collaboration;

use OCA\GroupShareMachine\Db\ClassGroupMapper;
use OCA\GroupShareMachine\Db\TeacherMapper;
use OCP\Collaboration\Collaborators\ISearchPlugin;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\Collaboration\Collaborators\SearchResultType;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Share\IShare;

/**
 * Surfaces class groups in the share-with picker for users marked as
 * teachers. Necessary because the standard GroupPlugin filters its results
 * to groups the searcher is actually a member of (when
 * shareapi_only_share_with_group_members=yes), and our virtualised teacher
 * membership doesn't show through getUserGroups() by design.
 */
class TeacherClassSearchPlugin implements ISearchPlugin {

	public function __construct(
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private TeacherMapper $teacherMapper,
		private ClassGroupMapper $groupMapper,
	) {
	}

	public function search($search, $limit, $offset, ISearchResult $searchResult): bool {
		$searcher = $this->userSession->getUser();
		if ($searcher === null) {
			return false;
		}
		if (!$this->teacherMapper->contains($searcher->getUID())) {
			return false;
		}

		$type = new SearchResultType('groups');
		$exact = [];
		$wide = [];

		foreach ($this->groupMapper->searchGids($search, $limit, $offset) as $gid) {
			if ($searchResult->hasResult($type, $gid)) {
				continue;
			}
			$group = $this->groupManager->get($gid);
			if ($group === null) {
				continue;
			}
			$displayName = $group->getDisplayName();
			$entry = [
				'label' => $displayName,
				'value' => [
					'shareType' => IShare::TYPE_GROUP,
					'shareWith' => $gid,
				],
			];
			if (strcasecmp($displayName, $search) === 0 || strcasecmp($gid, $search) === 0) {
				$exact[] = $entry;
			} else {
				$wide[] = $entry;
			}
		}

		$searchResult->addResultSet($type, $wide, $exact);
		return false;
	}
}
