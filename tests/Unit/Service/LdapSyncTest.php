<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Tests\Unit\Service;

use OCA\GroupShareMachine\Db\ClassGroupMapper;
use OCA\GroupShareMachine\Db\TeacherMapper;
use OCA\GroupShareMachine\Service\LdapSync;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Test\TestCase;

/**
 * Pure-unit tests for LdapSync. We override resolveProxy() in a subclass so
 * the test injects an in-memory fake proxy + fake Access — no real LDAP or
 * user_ldap dependency.
 */
class LdapSyncTest extends TestCase {

	private ClassGroupMapper&MockObject $groupMapper;
	private TeacherMapper&MockObject $teacherMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->groupMapper = $this->createMock(ClassGroupMapper::class);
		$this->teacherMapper = $this->createMock(TeacherMapper::class);
	}

	public function testGroupWithAllowedTypeIsUpserted(): void {
		$sync = $this->makeSync(
			groups: ['class_1a' => ['year class']],
			users: [],
		);

		$this->groupMapper->expects($this->once())->method('upsert')
			->with('class_1a', 'year class');
		$this->groupMapper->expects($this->never())->method('deleteByGid');
		$this->groupMapper->expects($this->once())->method('deleteNotIn')
			->with(['class_1a'])->willReturn(0);
		$this->teacherMapper->expects($this->once())->method('deleteNotIn')
			->with([])->willReturn(0);

		$stats = $sync->run();
		$this->assertSame(['seen' => 1, 'kept' => 1, 'pruned' => 0], $stats['groups']);
	}

	public function testGroupWithDisallowedTypeIsDeleted(): void {
		$sync = $this->makeSync(
			groups: ['random_group' => ['some_other_type']],
			users: [],
		);

		$this->groupMapper->expects($this->never())->method('upsert');
		$this->groupMapper->expects($this->once())->method('deleteByGid')
			->with('random_group');
		$this->groupMapper->expects($this->once())->method('deleteNotIn')
			->with([])->willReturn(0);

		$stats = $sync->run();
		$this->assertSame(['seen' => 1, 'kept' => 0, 'pruned' => 0], $stats['groups']);
	}

	public function testGroupWithMissingAttributeIsDeleted(): void {
		$sync = $this->makeSync(
			groups: ['no_attr' => []],   // empty multi-value list
			users: [],
		);

		$this->groupMapper->expects($this->never())->method('upsert');
		$this->groupMapper->expects($this->once())->method('deleteByGid')
			->with('no_attr');

		$sync->run();
	}

	public function testMultiValuedTeacherAffiliationUpserts(): void {
		// The bug we shipped once: user has 'admin' AND 'teacher'.
		// user_ldap flattens to 'admin'; reading the raw multi-valued attribute
		// must still pick out 'teacher'.
		$sync = $this->makeSync(
			groups: [],
			users: ['eluttine' => ['admin', 'teacher']],
		);

		$this->teacherMapper->expects($this->once())->method('upsert')
			->with('eluttine');
		$this->teacherMapper->expects($this->once())->method('deleteNotIn')
			->with(['eluttine'])->willReturn(0);

		$stats = $sync->run();
		$this->assertSame(['seen' => 1, 'kept' => 1, 'pruned' => 0], $stats['teachers']);
	}

	public function testUserWithoutTeacherAffiliationIsNotUpserted(): void {
		$sync = $this->makeSync(
			groups: [],
			users: [
				'alice' => ['admin', 'staff'],
				'bob' => ['student'],
			],
		);

		$this->teacherMapper->expects($this->never())->method('upsert');
		$this->teacherMapper->expects($this->once())->method('deleteNotIn')
			->with([])->willReturn(0);

		$stats = $sync->run();
		$this->assertSame(['seen' => 2, 'kept' => 0, 'pruned' => 0], $stats['teachers']);
	}

	public function testDeleteNotInReceivesKeptList(): void {
		$sync = $this->makeSync(
			groups: [
				'class_a' => ['year class'],
				'random' => ['unknown'],
				'class_b' => ['teaching_group'],
			],
			users: [
				'teacher1' => ['teacher'],
				'teacher2' => ['admin', 'teacher'],
				'student1' => ['student'],
			],
		);

		$this->groupMapper->expects($this->once())->method('deleteNotIn')
			->with(['class_a', 'class_b'])->willReturn(0);
		$this->teacherMapper->expects($this->once())->method('deleteNotIn')
			->with(['teacher1', 'teacher2'])->willReturn(0);

		$sync->run();
	}

	public function testProxyAbsentBecomesNoOp(): void {
		// When user_ldap isn't installed, resolveProxy returns null and we
		// shouldn't write anything to the tables.
		$sync = new class ($this->groupMapper, $this->teacherMapper, new NullLogger()) extends LdapSync {
			protected function resolveProxy(string $class): ?object {
				return null;
			}
		};

		$this->groupMapper->expects($this->never())->method('upsert');
		$this->groupMapper->expects($this->never())->method('deleteByGid');
		$this->groupMapper->expects($this->never())->method('deleteNotIn');
		$this->teacherMapper->expects($this->never())->method('upsert');
		$this->teacherMapper->expects($this->never())->method('deleteNotIn');

		$stats = $sync->run();
		$this->assertSame(
			[
				'groups' => ['seen' => 0, 'kept' => 0, 'pruned' => 0],
				'teachers' => ['seen' => 0, 'kept' => 0, 'pruned' => 0],
			],
			$stats,
		);
	}

	/**
	 * Build an LdapSync subclass whose resolveProxy() returns fake proxies
	 * driven by the canned $groups (gid => affiliation values) and
	 * $users (uid => affiliation values) maps.
	 *
	 * @param array<string, list<string>> $groups
	 * @param array<string, list<string>> $users
	 */
	private function makeSync(array $groups, array $users): LdapSync {
		$access = new class ($groups, $users) {
			/**
			 * @param array<string, list<string>> $groups
			 * @param array<string, list<string>> $users
			 */
			public function __construct(
				private array $groups,
				private array $users,
			) {
			}

			public function groupname2dn(string $gid): false|string {
				return isset($this->groups[$gid]) ? "cn={$gid},ou=Groups" : false;
			}

			public function username2dn(string $uid): false|string {
				return isset($this->users[$uid]) ? "uid={$uid},ou=People" : false;
			}

			/**
			 * @return list<string>|false
			 */
			public function readAttribute(string $dn, string $attr): array|false {
				if ($attr === LdapSync::GROUP_TYPE_ATTR) {
					foreach ($this->groups as $gid => $values) {
						if ($dn === "cn={$gid},ou=Groups") {
							return $values;
						}
					}
					return false;
				}
				if ($attr === LdapSync::AFFILIATION_ATTR) {
					foreach ($this->users as $uid => $values) {
						if ($dn === "uid={$uid},ou=People") {
							return $values;
						}
					}
					return false;
				}
				return false;
			}
		};

		$groupProxy = new class (array_keys($groups), $access) {
			/**
			 * @param list<string> $gids
			 */
			public function __construct(private array $gids, private object $access) {
			}

			/**
			 * @return list<string>
			 */
			public function getGroups(string $search, int $limit, int $offset): array {
				return array_slice($this->gids, $offset, $limit);
			}

			public function getLDAPAccess(string $gid): object {
				return $this->access;
			}
		};

		$userProxy = new class (array_keys($users), $access) {
			/**
			 * @param list<string> $uids
			 */
			public function __construct(private array $uids, private object $access) {
			}

			/**
			 * @return list<string>
			 */
			public function getUsers(string $search, int $limit, int $offset): array {
				return array_slice($this->uids, $offset, $limit);
			}

			public function getLDAPAccess(string $uid): object {
				return $this->access;
			}
		};

		return new class ($this->groupMapper, $this->teacherMapper, new NullLogger(), $groupProxy, $userProxy) extends LdapSync {
			public function __construct(
				ClassGroupMapper $groupMapper,
				TeacherMapper $teacherMapper,
				NullLogger $logger,
				private object $groupProxy,
				private object $userProxy,
			) {
				parent::__construct($groupMapper, $teacherMapper, $logger);
			}

			protected function resolveProxy(string $class): ?object {
				if ($class === 'OCA\\User_LDAP\\Group_Proxy') {
					return $this->groupProxy;
				}
				if ($class === 'OCA\\User_LDAP\\User_Proxy') {
					return $this->userProxy;
				}
				return null;
			}
		};
	}
}
