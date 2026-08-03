<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Tests\Unit\GroupBackend;

use OCA\GroupShareMachine\Db\ClassGroupMapper;
use OCA\GroupShareMachine\Db\TeacherMapper;
use OCA\GroupShareMachine\GroupBackend\TeacherClassMembership;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class TeacherClassMembershipTest extends TestCase {

	private ClassGroupMapper&MockObject $groupMapper;
	private TeacherMapper&MockObject $teacherMapper;
	private TeacherClassMembership $backend;

	protected function setUp(): void {
		parent::setUp();
		$this->groupMapper = $this->createMock(ClassGroupMapper::class);
		$this->teacherMapper = $this->createMock(TeacherMapper::class);
		$this->backend = new TeacherClassMembership($this->groupMapper, $this->teacherMapper);
	}

	public function testInGroupTrueWhenTeacherInGroupsSchool(): void {
		$schoolA = 'puavoId=1,ou=Groups';
		$this->groupMapper->method('getSchoolDn')->with('class_1a')->willReturn($schoolA);
		$this->teacherMapper->method('belongsToSchool')->with('alice', $schoolA)->willReturn(true);

		$this->assertTrue($this->backend->inGroup('alice', 'class_1a'));
	}

	public function testInGroupFalseWhenGroupHasNoSchool(): void {
		$this->groupMapper->method('getSchoolDn')->with('not_a_class')->willReturn(null);
		// teacherMapper should not be consulted — short-circuit when no school
		$this->teacherMapper->expects($this->never())->method('belongsToSchool');

		$this->assertFalse($this->backend->inGroup('alice', 'not_a_class'));
	}

	public function testInGroupFalseWhenTeacherInDifferentSchool(): void {
		$schoolA = 'puavoId=1,ou=Groups';
		$this->groupMapper->method('getSchoolDn')->with('class_1a')->willReturn($schoolA);
		// teacher exists but not in school A
		$this->teacherMapper->method('belongsToSchool')->with('alice', $schoolA)->willReturn(false);

		$this->assertFalse($this->backend->inGroup('alice', 'class_1a'));
	}

	public function testInGroupFalseWhenUserNotTeacher(): void {
		$schoolA = 'puavoId=1,ou=Groups';
		$this->groupMapper->method('getSchoolDn')->with('class_1a')->willReturn($schoolA);
		$this->teacherMapper->method('belongsToSchool')->with('student1', $schoolA)->willReturn(false);

		$this->assertFalse($this->backend->inGroup('student1', 'class_1a'));
	}

	public function testGroupExistsMirrorsGroupMapper(): void {
		$this->groupMapper->method('contains')
			->willReturnCallback(static fn (string $gid): bool => $gid === 'class_1a');

		$this->assertTrue($this->backend->groupExists('class_1a'));
		$this->assertFalse($this->backend->groupExists('something_else'));
	}

	public function testGetUserGroupsAlwaysEmpty(): void {
		// Even when the user is a known teacher, we deliberately return [].
		$this->teacherMapper->method('belongsToSchool')->willReturn(true);

		$this->assertSame([], $this->backend->getUserGroups('any_teacher'));
	}

	public function testEnumerationMethodsAlwaysEmpty(): void {
		$this->assertSame([], $this->backend->getGroups());
		$this->assertSame([], $this->backend->getGroups('search', 10, 0));
		$this->assertSame([], $this->backend->usersInGroup('class_1a'));
	}

	public function testImplementsActionsFalse(): void {
		$this->assertFalse($this->backend->implementsActions(0xFFFFFFFF));
	}
}
