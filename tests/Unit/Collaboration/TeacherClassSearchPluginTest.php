<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Tests\Unit\Collaboration;

use OCA\GroupShareMachine\Collaboration\TeacherClassSearchPlugin;
use OCA\GroupShareMachine\Db\ClassGroupMapper;
use OCA\GroupShareMachine\Db\TeacherMapper;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\Collaboration\Collaborators\SearchResultType;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class TeacherClassSearchPluginTest extends TestCase {

	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private TeacherMapper&MockObject $teacherMapper;
	private ClassGroupMapper&MockObject $groupMapper;
	private ISearchResult&MockObject $searchResult;
	private TeacherClassSearchPlugin $plugin;

	protected function setUp(): void {
		parent::setUp();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->teacherMapper = $this->createMock(TeacherMapper::class);
		$this->groupMapper = $this->createMock(ClassGroupMapper::class);
		$this->searchResult = $this->createMock(ISearchResult::class);

		$this->plugin = new TeacherClassSearchPlugin(
			$this->userSession,
			$this->groupManager,
			$this->teacherMapper,
			$this->groupMapper,
		);
	}

	public function testNoContributionWhenLoggedOut(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->teacherMapper->expects($this->never())->method('contains');
		$this->groupMapper->expects($this->never())->method('searchGids');
		$this->searchResult->expects($this->never())->method('addResultSet');

		$this->assertFalse($this->plugin->search('nct', 100, 0, $this->searchResult));
	}

	public function testNoContributionWhenNotTeacher(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('student1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->teacherMapper->method('contains')->with('student1')->willReturn(false);

		$this->groupMapper->expects($this->never())->method('searchGids');
		$this->searchResult->expects($this->never())->method('addResultSet');

		$this->assertFalse($this->plugin->search('nct', 100, 0, $this->searchResult));
	}

	public function testTeacherGetsClassGroups(): void {
		$teacher = $this->createMock(IUser::class);
		$teacher->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($teacher);
		$this->teacherMapper->method('contains')->with('alice')->willReturn(true);

		$this->groupMapper->method('searchGids')
			->with('nct', 100, 0)
			->willReturn(['nct-2024', 'nct-2025']);

		$g1 = $this->mockGroup('NCT - 1.luokka');
		$g2 = $this->mockGroup('NCT - 2.luokka');
		$this->groupManager->method('get')->willReturnMap([
			['nct-2024', $g1],
			['nct-2025', $g2],
		]);

		$this->searchResult->method('hasResult')->willReturn(false);

		$this->searchResult->expects($this->once())
			->method('addResultSet')
			->with(
				$this->callback(static fn (SearchResultType $t): bool => $t->getLabel() === 'groups'),
				$this->callback(function (array $wide): bool {
					$labels = array_map(static fn (array $e): string => $e['label'], $wide);
					sort($labels);
					return $labels === ['NCT - 1.luokka', 'NCT - 2.luokka'];
				}),
				[],
			);

		$this->assertFalse($this->plugin->search('nct', 100, 0, $this->searchResult));
	}

	public function testExactMatchGoesToExactBucket(): void {
		$teacher = $this->createMock(IUser::class);
		$teacher->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($teacher);
		$this->teacherMapper->method('contains')->willReturn(true);

		$this->groupMapper->method('searchGids')->willReturn(['nct-2024']);
		$this->groupManager->method('get')->willReturn($this->mockGroup('nct-2024'));
		$this->searchResult->method('hasResult')->willReturn(false);

		$this->searchResult->expects($this->once())
			->method('addResultSet')
			->with(
				$this->isInstanceOf(SearchResultType::class),
				[],                                              // wide — empty
				$this->callback(static function (array $exact): bool {
					return count($exact) === 1
						&& $exact[0]['value']['shareWith'] === 'nct-2024'
						&& $exact[0]['value']['shareType'] === IShare::TYPE_GROUP;
				}),
			);

		// search term matches the gid exactly → exact bucket
		$this->plugin->search('nct-2024', 100, 0, $this->searchResult);
	}

	public function testSkipsGroupsAlreadyInResult(): void {
		$teacher = $this->createMock(IUser::class);
		$teacher->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($teacher);
		$this->teacherMapper->method('contains')->willReturn(true);

		$this->groupMapper->method('searchGids')->willReturn(['nct-2024', 'nct-2025']);
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $gid): IGroup => $this->mockGroup($gid),
		);

		// nct-2024 already contributed by another plugin
		$this->searchResult->method('hasResult')->willReturnCallback(
			static fn (SearchResultType $t, string $id): bool => $id === 'nct-2024',
		);

		$this->searchResult->expects($this->once())
			->method('addResultSet')
			->with(
				$this->isInstanceOf(SearchResultType::class),
				$this->callback(static function (array $wide): bool {
					return count($wide) === 1
						&& $wide[0]['value']['shareWith'] === 'nct-2025';
				}),
				[],
			);

		$this->plugin->search('nct', 100, 0, $this->searchResult);
	}

	public function testSkipsUnresolvableGroups(): void {
		$teacher = $this->createMock(IUser::class);
		$teacher->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($teacher);
		$this->teacherMapper->method('contains')->willReturn(true);

		$this->groupMapper->method('searchGids')->willReturn(['nct-2024', 'ghost']);
		$this->groupManager->method('get')->willReturnMap([
			['nct-2024', $this->mockGroup('NCT - 1.luokka')],
			['ghost', null],                                  // table has it, NC doesn't
		]);
		$this->searchResult->method('hasResult')->willReturn(false);

		$this->searchResult->expects($this->once())
			->method('addResultSet')
			->with(
				$this->isInstanceOf(SearchResultType::class),
				$this->callback(static function (array $wide): bool {
					$labels = array_map(static fn (array $e): string => $e['label'], $wide);
					return $labels === ['NCT - 1.luokka'];
				}),
				[],
			);

		$this->plugin->search('nct', 100, 0, $this->searchResult);
	}

	private function mockGroup(string $displayName): IGroup&MockObject {
		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn($displayName);
		$group->method('getGID')->willReturn($displayName);
		return $group;
	}
}
