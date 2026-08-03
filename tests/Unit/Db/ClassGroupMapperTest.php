<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Tests\Unit\Db;

use OCA\GroupShareMachine\Db\ClassGroupMapper;
use OCP\IDBConnection;
use OCP\Server;
use Test\TestCase;

#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
class ClassGroupMapperTest extends TestCase {

	private IDBConnection $db;
	private ClassGroupMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->mapper = new ClassGroupMapper($this->db);
		$this->wipe();
	}

	protected function tearDown(): void {
		$this->wipe();
		parent::tearDown();
	}

	private function wipe(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(ClassGroupMapper::TABLE)->executeStatement();
	}

	public function testContainsReturnsFalseWhenAbsent(): void {
		$this->assertFalse($this->mapper->contains('absent'));
	}

	public function testUpsertStoresAndUpdatesSchoolName(): void {
		$this->mapper->upsert('class_1a', 'year class', 'School Alpha');
		$this->assertSame('School Alpha', $this->mapper->getSchoolName('class_1a'));

		// upsert again with a different school name — should overwrite, not duplicate
		$this->mapper->upsert('class_1a', 'year class', 'School Beta');
		$this->assertSame('School Beta', $this->mapper->getSchoolName('class_1a'));

		// upsert with NULL clears the school name
		$this->mapper->upsert('class_1a', 'year class', null);
		$this->assertNull($this->mapper->getSchoolName('class_1a'));
	}

	public function testGetSchoolNameMissingGid(): void {
		$this->assertNull($this->mapper->getSchoolName('does_not_exist'));
	}

	public function testSearchEntriesIncludesSchoolName(): void {
		$this->mapper->upsert('class_1a', 'year class', 'School A', 'puavoId=1,ou=Groups');
		$this->mapper->upsert('class_1b', 'year class', null, '');

		$entries = $this->mapper->searchEntries('class_', 100, 0);
		usort($entries, static fn (array $a, array $b): int => strcmp($a['gid'], $b['gid']));

		$this->assertSame([
			['gid' => 'class_1a', 'school_name' => 'School A', 'school_dn' => 'puavoId=1,ou=Groups'],
			['gid' => 'class_1b', 'school_name' => null, 'school_dn' => ''],
		], $entries);
	}

	public function testSearchEntriesForSchoolsFiltersByDn(): void {
		$schoolA = 'puavoId=1,ou=Groups';
		$schoolB = 'puavoId=2,ou=Groups';
		$this->mapper->upsert('a_class', 'year class', 'A', $schoolA);
		$this->mapper->upsert('b_class', 'year class', 'B', $schoolB);
		$this->mapper->upsert('a_class2', 'year class', 'A', $schoolA);

		// Teacher only in school A — should see two A-classes, no B-classes.
		$entries = $this->mapper->searchEntriesForSchools('', [$schoolA], 100, 0);
		$gids = array_column($entries, 'gid');
		sort($gids);
		$this->assertSame(['a_class', 'a_class2'], $gids);

		// Empty school set — never returns anything.
		$this->assertSame([], $this->mapper->searchEntriesForSchools('', [], 100, 0));
	}

	public function testGetSchoolDn(): void {
		$schoolA = 'puavoId=1,ou=Groups';
		$this->mapper->upsert('class_1a', 'year class', 'School A', $schoolA);
		$this->mapper->upsert('orphan', 'year class', null, '');

		$this->assertSame($schoolA, $this->mapper->getSchoolDn('class_1a'));
		$this->assertNull($this->mapper->getSchoolDn('orphan'));    // empty string treated as missing
		$this->assertNull($this->mapper->getSchoolDn('nonexistent'));
	}

	public function testUpsertInsertsThenUpdates(): void {
		$this->mapper->upsert('class_1a', 'year class');
		$this->assertTrue($this->mapper->contains('class_1a'));
		$this->assertSame(['class_1a'], $this->mapper->listGids());

		// upsert again with a new type — should update in place, not duplicate
		$this->mapper->upsert('class_1a', 'teaching group');
		$this->assertSame(['class_1a'], $this->mapper->listGids());

		$row = $this->db->getQueryBuilder()
			->select('group_type')
			->from(ClassGroupMapper::TABLE)
			->executeQuery()
			->fetch();
		$this->assertSame('teaching group', $row['group_type']);
	}

	public function testDeleteByGid(): void {
		$this->mapper->upsert('class_1a', 'year class');
		$this->mapper->upsert('class_1b', 'year class');

		$this->mapper->deleteByGid('class_1a');

		$this->assertFalse($this->mapper->contains('class_1a'));
		$this->assertTrue($this->mapper->contains('class_1b'));
	}

	public function testDeleteNotInPrunesMissing(): void {
		$this->mapper->upsert('a', 'year class');
		$this->mapper->upsert('b', 'year class');
		$this->mapper->upsert('c', 'year class');

		$removed = $this->mapper->deleteNotIn(['a', 'c']);

		$this->assertSame(1, $removed);
		$this->assertEqualsCanonicalizing(['a', 'c'], $this->mapper->listGids());
	}

	public function testDeleteNotInEmptyListRemovesAll(): void {
		$this->mapper->upsert('a', 'year class');
		$this->mapper->upsert('b', 'year class');

		$removed = $this->mapper->deleteNotIn([]);

		$this->assertSame(2, $removed);
		$this->assertSame([], $this->mapper->listGids());
	}

	public function testSearchGidsSubstringMatch(): void {
		$this->mapper->upsert('class_1a', 'year class');
		$this->mapper->upsert('class_2b', 'year class');
		$this->mapper->upsert('teaching_math', 'teaching group');

		$this->assertEqualsCanonicalizing(
			['class_1a', 'class_2b'],
			$this->mapper->searchGids('class', 100, 0),
		);
		$this->assertSame(['teaching_math'], $this->mapper->searchGids('math', 100, 0));
		$this->assertSame([], $this->mapper->searchGids('nonexistent', 100, 0));
	}

	public function testSearchGidsEmptyQueryReturnsAll(): void {
		$this->mapper->upsert('a', 'year class');
		$this->mapper->upsert('b', 'year class');

		$this->assertEqualsCanonicalizing(['a', 'b'], $this->mapper->searchGids('', 100, 0));
	}
}
