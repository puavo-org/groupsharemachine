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
 * the test injects an in-memory fake Group_Proxy / User_Proxy whose Access
 * obeys our combined-filter contract — no real LDAP and no user_ldap.
 */
class LdapSyncTest extends TestCase {

	private ClassGroupMapper&MockObject $groupMapper;
	private TeacherMapper&MockObject $teacherMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->groupMapper = $this->createMock(ClassGroupMapper::class);
		$this->teacherMapper = $this->createMock(TeacherMapper::class);
	}

	public function testGroupSearchUpsertsAllowedTypes(): void {
		$sync = $this->makeSync(
			groups: [
				'puavoid=10,ou=groups' => ['gid' => 'class_1a', 'puavoedugrouptype' => ['year class']],
				'puavoid=11,ou=groups' => ['gid' => 'math_g',   'puavoedugrouptype' => ['teaching group']],
			],
			users: [],
		);

		$this->groupMapper->expects($this->exactly(2))->method('upsert')
			->willReturnCallback(function (string $gid, string $type, ?string $schoolName, string $schoolDn): void {
				$this->assertContains($gid, ['class_1a', 'math_g']);
				$this->assertContains($type, LdapSync::ALLOWED_GROUP_TYPES);
				$this->assertNull($schoolName);
				$this->assertSame('', $schoolDn);
			});
		$this->groupMapper->expects($this->once())->method('deleteNotIn')
			->with($this->callback(static fn (array $kept): bool => count($kept) === 2))
			->willReturn(0);
		// Empty users list → no User_Proxy sample → teacher sync skipped entirely.

		$stats = $sync->run();
		$this->assertSame(['seen' => 2, 'kept' => 2, 'pruned' => 0], $stats['groups']);
		$this->assertSame(['seen' => 0, 'kept' => 0, 'pruned' => 0], $stats['teachers']);
	}

	public function testGroupSearchSkipsRecordsWithoutDnOrType(): void {
		$sync = $this->makeSync(
			groups: [
				'puavoid=10,ou=groups' => ['gid' => 'class_1a', 'puavoedugrouptype' => ['year class']],
				'__no_dn__' => ['gid' => null, 'puavoedugrouptype' => ['year class']],
				'puavoid=12,ou=groups' => ['gid' => 'orphan', 'puavoedugrouptype' => []],
			],
			users: [],
		);

		$this->groupMapper->expects($this->once())->method('upsert')
			->with('class_1a', 'year class', null, '');
		$this->groupMapper->expects($this->once())->method('deleteNotIn')
			->with(['class_1a']);

		$sync->run();
	}

	public function testGroupSearchResolvesAndCachesSchoolName(): void {
		// Two class groups in the same school, plus one in a different school.
		// LdapSync should resolve the school's displayname via readAttribute
		// and cache it within the run — only 2 LDAP attribute reads, not 3.
		$schoolA = 'puavoId=1,ou=Schools,dc=example,dc=test';
		$schoolB = 'puavoId=2,ou=Schools,dc=example,dc=test';

		$sync = $this->makeSync(
			groups: [
				'puavoid=10,ou=groups' => [
					'gid' => 'class_1a',
					'puavoedugrouptype' => ['year class'],
					'puavoschool' => [$schoolA],
				],
				'puavoid=11,ou=groups' => [
					'gid' => 'class_2a',
					'puavoedugrouptype' => ['year class'],
					'puavoschool' => [$schoolA],
				],
				'puavoid=12,ou=groups' => [
					'gid' => 'class_1b',
					'puavoedugrouptype' => ['year class'],
					'puavoschool' => [$schoolB],
				],
			],
			users: [],
			schools: [
				$schoolA => 'School Alpha',
				$schoolB => 'School Beta',
			],
		);

		$expected = [
			'class_1a' => ['School Alpha', $schoolA],
			'class_2a' => ['School Alpha', $schoolA],
			'class_1b' => ['School Beta', $schoolB],
		];
		$this->groupMapper->expects($this->exactly(3))->method('upsert')
			->willReturnCallback(function (string $gid, string $type, ?string $schoolName, string $schoolDn) use ($expected): void {
				$this->assertSame($expected[$gid][0], $schoolName);
				$this->assertSame($expected[$gid][1], $schoolDn);
			});
		$this->groupMapper->expects($this->once())->method('deleteNotIn');

		$sync->run();

		// Each school DN should be looked up exactly once (cache hit on the
		// second class of the same school).
		$counts = $sync->getReadAttributeCounts();
		$this->assertSame(1, $counts[$schoolA] ?? 0);
		$this->assertSame(1, $counts[$schoolB] ?? 0);
	}

	public function testTeacherSearchWritesOneRowPerSchool(): void {
		// Each user's puavoSchool can be multi-valued — write one (uid, school)
		// row per school. The LDAP filter matches users whose affiliation
		// contains 'teacher'; the server pre-filters that.
		$schoolA = 'puavoId=10,ou=Groups,dc=edu';
		$schoolB = 'puavoId=20,ou=Groups,dc=edu';
		$sync = $this->makeSync(
			groups: [],
			users: [
				'puavoId=11,ou=People' => ['uid' => 'alice', 'puavoschool' => [$schoolA, $schoolB]],
				'puavoId=12,ou=People' => ['uid' => 'bob', 'puavoschool' => [$schoolA]],
			],
		);

		// 3 upsert calls total: (alice, A), (alice, B), (bob, A)
		$expected = [
			['alice', $schoolA],
			['alice', $schoolB],
			['bob', $schoolA],
		];
		$this->teacherMapper->expects($this->exactly(3))->method('upsert')
			->willReturnCallback(function (string $uid, string $schoolDn) use ($expected): void {
				$this->assertContains([$uid, $schoolDn], $expected);
			});
		$this->teacherMapper->expects($this->once())->method('deleteNotIn')
			->with($this->callback(static fn (array $kept): bool => count($kept) === 3))
			->willReturn(0);

		$stats = $sync->run();
		$this->assertSame(['seen' => 2, 'kept' => 3, 'pruned' => 0], $stats['teachers']);
	}

	public function testTeacherWithoutAnySchoolIsSkipped(): void {
		// A teacher entry without puavoSchool can't be scoped — record nothing.
		$sync = $this->makeSync(
			groups: [],
			users: [
				'puavoid=999,ou=people' => ['uid' => '999'],   // no 'puavoschool' key
			],
		);

		$this->teacherMapper->expects($this->never())->method('upsert');
		$this->teacherMapper->expects($this->once())->method('deleteNotIn')->with([]);

		$sync->run();
	}

	public function testTeacherSearchSkipsRecordsWithoutDn(): void {
		$schoolA = 'puavoId=10,ou=Groups,dc=edu';
		$sync = $this->makeSync(
			groups: [],
			users: [
				'puavoId=11,ou=People' => ['uid' => 'alice', 'puavoschool' => [$schoolA]],
				'__no_dn__' => ['uid' => null, 'puavoschool' => [$schoolA]],
			],
		);

		$this->teacherMapper->expects($this->once())->method('upsert')
			->with('alice', $schoolA);
		$this->teacherMapper->expects($this->once())->method('deleteNotIn')
			->with([['alice', $schoolA]]);

		$sync->run();
	}

	public function testCombinedFilterIncludesConfiguredFilterAndPredicate(): void {
		$sync = $this->makeSync(
			groups: ['puavoid=10,ou=groups' => ['gid' => 'class_1a', 'puavoedugrouptype' => ['year class']]],
			users: ['puavoid=20,ou=people' => ['uid' => 'alice']],
		);
		$sync->run();

		$groupFilters = $sync->getCapturedGroupFilters();
		$this->assertNotEmpty($groupFilters);
		$last = end($groupFilters);
		$this->assertCount(2, $last);
		$this->assertSame('(objectClass=groupOfFakes)', $last[0]);
		$this->assertStringContainsString('puavoEduGroupType', $last[1]);
		$this->assertStringContainsString('year class', $last[1]);

		$userFilters = $sync->getCapturedUserFilters();
		$lastUser = end($userFilters);
		$this->assertSame('(objectClass=puavoEduPerson)', $lastUser[0]);
		$this->assertStringContainsString('puavoEduPersonAffiliation=teacher', $lastUser[1]);
	}

	public function testProxyAbsentBecomesNoOp(): void {
		$sync = new class($this->groupMapper, $this->teacherMapper, new NullLogger()) extends LdapSync {
			protected function resolveProxy(string $class): ?object {
				return null;
			}
		};

		$this->groupMapper->expects($this->never())->method('upsert');
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

	public function testEmptyLdapBecomesNoOp(): void {
		$sync = $this->makeSync(groups: [], users: []);

		$this->groupMapper->expects($this->never())->method('upsert');
		$this->teacherMapper->expects($this->never())->method('upsert');

		$stats = $sync->run();
		$this->assertSame(0, $stats['groups']['seen']);
		$this->assertSame(0, $stats['teachers']['seen']);
	}

	/**
	 * Build an LdapSync subclass with fake Group_Proxy / User_Proxy.
	 *
	 * @param array<string, array{gid: ?string, puavoedugrouptype: list<string>}> $groups DN-keyed
	 * @param array<string, array{uid: ?string}> $users DN-keyed
	 */
	private function makeSync(array $groups, array $users, array $schools = []): LdapSyncTestDouble {
		$groupAccess = $this->buildAccess($groups, $schools);
		$userAccess = $this->buildAccess($users, []);

		$groupSample = array_values($groups)[0]['gid'] ?? null;
		$userSample = array_values($users)[0]['uid'] ?? null;
		$groupProxy = $this->buildProxy($groupAccess, $groupSample);
		$userProxy = $this->buildProxy($userAccess, $userSample);

		return new LdapSyncTestDouble(
			$this->groupMapper,
			$this->teacherMapper,
			new NullLogger(),
			$groupProxy,
			$userProxy,
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $records DN-keyed test data
	 */
	private function buildAccess(array $records, array $schoolDirectory): object {
		return new class($records, $schoolDirectory) {
			/** @var list<array<int, string>> */
			public array $capturedFilterCalls = [];

			/** @var array<string, int> readAttribute call counts per DN */
			public array $readAttributeCalls = [];

			public function __construct(
				private array $records,
				private array $schoolDirectory,
			) {
			}

			public function getConnection(): object {
				return new class {
					public string $ldapGroupFilter = '(objectClass=groupOfFakes)';
					public string $ldapUserFilter = '(objectClass=puavoEduPerson)';
				};
			}

			public function combineFilterWithAnd(array $filters): string {
				$this->capturedFilterCalls[] = $filters;
				return '(&' . implode('', $filters) . ')';
			}

			public function searchGroups(string $filter, array $attr, int $limit, int $offset): array {
				return $this->materialize($offset, $limit);
			}

			public function searchUsers(string $filter, array $attr, int $limit, int $offset): array {
				return $this->materialize($offset, $limit);
			}

			private function materialize(int $offset, int $limit): array {
				$out = [];
				$dns = array_keys($this->records);
				foreach (array_slice($dns, $offset, $limit) as $dn) {
					$row = ['dn' => str_starts_with($dn, '__no_dn__') ? [] : [$dn]];
					$attrs = $this->records[$dn];
					if (isset($attrs['puavoedugrouptype']) && $attrs['puavoedugrouptype'] !== []) {
						$row['puavoedugrouptype'] = $attrs['puavoedugrouptype'];
					}
					if (isset($attrs['puavoschool']) && $attrs['puavoschool'] !== []) {
						$row['puavoschool'] = $attrs['puavoschool'];
					}
					$out[] = $row;
				}
				return $out;
			}

			public function dn2ocname(string $dn, ?string $hint, bool $isUser): false|string {
				if (!isset($this->records[$dn])) {
					return false;
				}
				$key = $isUser ? 'uid' : 'gid';
				$value = $this->records[$dn][$key] ?? null;
				return is_string($value) ? $value : false;
			}

			public function readAttribute(string $dn, string $attr): array|false {
				$this->readAttributeCalls[$dn] = ($this->readAttributeCalls[$dn] ?? 0) + 1;
				if (strtolower($attr) === 'displayname' && isset($this->schoolDirectory[$dn])) {
					return [$this->schoolDirectory[$dn]];
				}
				return false;
			}
		};
	}

	private function buildProxy(object $access, ?string $sample): object {
		return new class($access, $sample) {
			public function __construct(
				private object $access,
				private ?string $sample,
			) {
			}

			public function getGroups(string $search, int $limit, int $offset): array {
				return $this->sample === null ? [] : [$this->sample];
			}

			public function getUsers(string $search, int $limit, int $offset): array {
				return $this->sample === null ? [] : [$this->sample];
			}

			public function getLDAPAccess(string $id): object {
				return $this->access;
			}
		};
	}
}

/**
 * Test-double subclass exposing fake proxies + captured filter inputs.
 */
class LdapSyncTestDouble extends LdapSync {

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

	/**
	 * @return list<array<int, string>>
	 */
	public function getCapturedGroupFilters(): array {
		$access = $this->groupProxy->getLDAPAccess('');
		return $access->capturedFilterCalls;
	}

	/**
	 * @return list<array<int, string>>
	 */
	public function getCapturedUserFilters(): array {
		$access = $this->userProxy->getLDAPAccess('');
		return $access->capturedFilterCalls;
	}

	/**
	 * @return array<string, int>
	 */
	public function getReadAttributeCounts(): array {
		$access = $this->groupProxy->getLDAPAccess('');
		return $access->readAttributeCalls;
	}
}
