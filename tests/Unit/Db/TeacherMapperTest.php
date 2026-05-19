<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Tests\Unit\Db;

use OCA\GroupShareMachine\Db\TeacherMapper;
use OCP\IDBConnection;
use OCP\Server;
use Test\TestCase;

#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
class TeacherMapperTest extends TestCase {

	private IDBConnection $db;
	private TeacherMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->mapper = new TeacherMapper($this->db);
		$this->wipe();
	}

	protected function tearDown(): void {
		$this->wipe();
		parent::tearDown();
	}

	private function wipe(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(TeacherMapper::TABLE)->executeStatement();
	}

	public function testContainsAndUpsertIdempotent(): void {
		$this->assertFalse($this->mapper->contains('alice'));

		$this->mapper->upsert('alice');
		$this->assertTrue($this->mapper->contains('alice'));

		// Second upsert is a no-op (still single row)
		$this->mapper->upsert('alice');
		$count = (int)$this->db->getQueryBuilder()
			->select($this->db->getQueryBuilder()->func()->count('*'))
			->from(TeacherMapper::TABLE)
			->executeQuery()
			->fetchOne();
		$this->assertSame(1, $count);
	}

	public function testDeleteNotInKeepsMatching(): void {
		$this->mapper->upsert('a');
		$this->mapper->upsert('b');
		$this->mapper->upsert('c');

		$removed = $this->mapper->deleteNotIn(['b']);

		$this->assertSame(2, $removed);
		$this->assertFalse($this->mapper->contains('a'));
		$this->assertTrue($this->mapper->contains('b'));
		$this->assertFalse($this->mapper->contains('c'));
	}

	public function testDeleteNotInEmptyRemovesAll(): void {
		$this->mapper->upsert('a');
		$this->mapper->upsert('b');

		$this->assertSame(2, $this->mapper->deleteNotIn([]));
		$this->assertFalse($this->mapper->contains('a'));
		$this->assertFalse($this->mapper->contains('b'));
	}
}
