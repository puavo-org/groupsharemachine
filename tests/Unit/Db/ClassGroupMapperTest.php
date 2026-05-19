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

	public function testUpsertInsertsThenUpdates(): void {
		$this->mapper->upsert('class_1a', 'year class');
		$this->assertTrue($this->mapper->contains('class_1a'));
		$this->assertSame(['class_1a'], $this->mapper->listGids());

		// upsert again with a new type — should update in place, not duplicate
		$this->mapper->upsert('class_1a', 'teaching_group');
		$this->assertSame(['class_1a'], $this->mapper->listGids());

		$row = $this->db->getQueryBuilder()
			->select('group_type')
			->from(ClassGroupMapper::TABLE)
			->executeQuery()
			->fetchAssociative();
		$this->assertSame('teaching_group', $row['group_type']);
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
		$this->mapper->upsert('teaching_math', 'teaching_group');

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
