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
		$this->teacherMapper->expects($this->never())->method('schoolsForTeacher');
		$this->groupMapper->expects($this->never())->method('searchEntriesForSchools');
		$this->searchResult->expects($this->never())->method('addResultSet');

		$this->assertFalse($this->plugin->search('nct', 100, 0, $this->searchResult));
	}

	public function testNoContributionWhenNotTeacherInAnySchool(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('student1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->teacherMapper->method('schoolsForTeacher')->with('student1')->willReturn([]);

		$this->groupMapper->expects($this->never())->method('searchEntriesForSchools');
		$this->searchResult->expects($this->never())->method('addResultSet');

		$this->assertFalse($this->plugin->search('nct', 100, 0, $this->searchResult));
	}

	public function testTeacherGetsClassGroups(): void {
		$teacher = $this->createMock(IUser::class);
		$teacher->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($teacher);
		$this->teacherMapper->method('schoolsForTeacher')->with('alice')->willReturn(['puavoId=1,ou=Groups']);

		$this->groupMapper->method('searchEntriesForSchools')
			->willReturn([
				['gid' => 'class_alpha', 'school_name' => null],
				['gid' => 'class_beta', 'school_name' => null],
			]);

		$g1 = $this->mockGroup('Class Alpha');
		$g2 = $this->mockGroup('Class Beta');
		$this->groupManager->method('get')->willReturnMap([
			['class_alpha', $g1],
			['class_beta', $g2],
		]);

		$this->searchResult->method('hasResult')->willReturn(false);

		$this->searchResult->expects($this->once())
			->method('addResultSet')
			->with(
				$this->callback(static fn (SearchResultType $t): bool => $t->getLabel() === 'groups'),
				$this->callback(function (array $wide): bool {
					$labels = array_map(static fn (array $e): string => $e['label'], $wide);
					sort($labels);
					return $labels === ['Class Alpha', 'Class Beta'];
				}),
				[],
			);

		$this->assertFalse($this->plugin->search('nct', 100, 0, $this->searchResult));
	}

	public function testLabelDisambiguatedWithSchoolName(): void {
		// Two class groups with the same display name across two schools — the
		// labels in the picker should be disambiguated with the school suffix.
		$teacher = $this->createMock(IUser::class);
		$teacher->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($teacher);
		$this->teacherMapper->method('schoolsForTeacher')->willReturn(['puavoId=1,ou=Groups']);

		$this->groupMapper->method('searchEntriesForSchools')->willReturn([
			['gid' => 'school_a_1a', 'school_name' => 'School Alpha'],
			['gid' => 'school_b_1a', 'school_name' => 'School Beta'],
		]);

		$this->groupManager->method('get')->willReturnMap([
			['school_a_1a', $this->mockGroup('1.luokka')],
			['school_b_1a', $this->mockGroup('1.luokka')],
		]);
		$this->searchResult->method('hasResult')->willReturn(false);

		$this->searchResult->expects($this->once())
			->method('addResultSet')
			->with(
				$this->isInstanceOf(SearchResultType::class),
				$this->callback(static function (array $wide): bool {
					$labels = array_map(static fn (array $e): string => $e['label'], $wide);
					sort($labels);
					return $labels === [
						'1.luokka (School Alpha)',
						'1.luokka (School Beta)',
					];
				}),
				[],
			);

		// Use a partial search so the entries land in the wide bucket; the
		// exact-match path is exercised by testExactMatchGoesToExactBucket.
		$this->plugin->search('luokka', 100, 0, $this->searchResult);
	}

	public function testLabelOmitsSuffixWhenSchoolNameMissing(): void {
		$teacher = $this->createMock(IUser::class);
		$teacher->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($teacher);
		$this->teacherMapper->method('schoolsForTeacher')->willReturn(['puavoId=1,ou=Groups']);

		$this->groupMapper->method('searchEntriesForSchools')->willReturn([
			['gid' => 'class_a', 'school_name' => null],
		]);
		$this->groupManager->method('get')->willReturn($this->mockGroup('Class A'));
		$this->searchResult->method('hasResult')->willReturn(false);

		$this->searchResult->expects($this->once())
			->method('addResultSet')
			->with(
				$this->isInstanceOf(SearchResultType::class),
				$this->callback(static function (array $wide): bool {
					return count($wide) === 1 && $wide[0]['label'] === 'Class A';
				}),
				[],
			);

		$this->plugin->search('class', 100, 0, $this->searchResult);
	}

	public function testExactMatchGoesToExactBucket(): void {
		$teacher = $this->createMock(IUser::class);
		$teacher->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($teacher);
		$this->teacherMapper->method('schoolsForTeacher')->willReturn(['puavoId=1,ou=Groups']);

		$this->groupMapper->method('searchEntriesForSchools')
			->willReturn([['gid' => 'class_alpha', 'school_name' => null]]);
		$this->groupManager->method('get')->willReturn($this->mockGroup('class_alpha'));
		$this->searchResult->method('hasResult')->willReturn(false);

		$this->searchResult->expects($this->once())
			->method('addResultSet')
			->with(
				$this->isInstanceOf(SearchResultType::class),
				[],                                              // wide — empty
				$this->callback(static function (array $exact): bool {
					return count($exact) === 1
						&& $exact[0]['value']['shareWith'] === 'class_alpha'
						&& $exact[0]['value']['shareType'] === IShare::TYPE_GROUP;
				}),
			);

		// search term matches the gid exactly → exact bucket
		$this->plugin->search('class_alpha', 100, 0, $this->searchResult);
	}

	public function testSkipsGroupsAlreadyInResult(): void {
		$teacher = $this->createMock(IUser::class);
		$teacher->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($teacher);
		$this->teacherMapper->method('schoolsForTeacher')->willReturn(['puavoId=1,ou=Groups']);

		$this->groupMapper->method('searchEntriesForSchools')->willReturn([
			['gid' => 'class_alpha', 'school_name' => null],
			['gid' => 'class_beta', 'school_name' => null],
		]);
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $gid): IGroup => $this->mockGroup($gid),
		);

		// class_alpha already contributed by another plugin
		$this->searchResult->method('hasResult')->willReturnCallback(
			static fn (SearchResultType $t, string $id): bool => $id === 'class_alpha',
		);

		$this->searchResult->expects($this->once())
			->method('addResultSet')
			->with(
				$this->isInstanceOf(SearchResultType::class),
				$this->callback(static function (array $wide): bool {
					return count($wide) === 1
						&& $wide[0]['value']['shareWith'] === 'class_beta';
				}),
				[],
			);

		$this->plugin->search('nct', 100, 0, $this->searchResult);
	}

	public function testSkipsUnresolvableGroups(): void {
		$teacher = $this->createMock(IUser::class);
		$teacher->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($teacher);
		$this->teacherMapper->method('schoolsForTeacher')->willReturn(['puavoId=1,ou=Groups']);

		$this->groupMapper->method('searchEntriesForSchools')->willReturn([
			['gid' => 'class_alpha', 'school_name' => null],
			['gid' => 'ghost', 'school_name' => null],
		]);
		$this->groupManager->method('get')->willReturnMap([
			['class_alpha', $this->mockGroup('Class Alpha')],
			['ghost', null],                                  // table has it, NC doesn't
		]);
		$this->searchResult->method('hasResult')->willReturn(false);

		$this->searchResult->expects($this->once())
			->method('addResultSet')
			->with(
				$this->isInstanceOf(SearchResultType::class),
				$this->callback(static function (array $wide): bool {
					$labels = array_map(static fn (array $e): string => $e['label'], $wide);
					return $labels === ['Class Alpha'];
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
