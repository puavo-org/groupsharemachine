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

	private const SCHOOL_A = 'puavoId=1,ou=Groups,dc=edu,dc=example';
	private const SCHOOL_B = 'puavoId=2,ou=Groups,dc=edu,dc=example';

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

	public function testUpsertIdempotentPerPair(): void {
		$this->mapper->upsert('alice', self::SCHOOL_A);
		$this->mapper->upsert('alice', self::SCHOOL_A);   // second call — no-op
		$this->assertCount(1, $this->mapper->schoolsForTeacher('alice'));
	}

	public function testMultiSchoolTeacher(): void {
		$this->mapper->upsert('alice', self::SCHOOL_A);
		$this->mapper->upsert('alice', self::SCHOOL_B);
		$this->mapper->upsert('bob', self::SCHOOL_A);

		$this->assertTrue($this->mapper->contains('alice'));
		$this->assertTrue($this->mapper->belongsToSchool('alice', self::SCHOOL_A));
		$this->assertTrue($this->mapper->belongsToSchool('alice', self::SCHOOL_B));
		$this->assertFalse($this->mapper->belongsToSchool('alice', 'puavoId=99,unknown'));

		$this->assertTrue($this->mapper->belongsToSchool('bob', self::SCHOOL_A));
		$this->assertFalse($this->mapper->belongsToSchool('bob', self::SCHOOL_B));

		$this->assertEqualsCanonicalizing(
			[self::SCHOOL_A, self::SCHOOL_B],
			$this->mapper->schoolsForTeacher('alice'),
		);
	}

	public function testContainsFalseWhenAbsent(): void {
		$this->assertFalse($this->mapper->contains('nobody'));
		$this->assertFalse($this->mapper->belongsToSchool('nobody', self::SCHOOL_A));
		$this->assertSame([], $this->mapper->schoolsForTeacher('nobody'));
	}

	public function testDeleteNotInPrunesPairs(): void {
		$this->mapper->upsert('alice', self::SCHOOL_A);
		$this->mapper->upsert('alice', self::SCHOOL_B);
		$this->mapper->upsert('bob', self::SCHOOL_A);

		$removed = $this->mapper->deleteNotIn([
			['alice', self::SCHOOL_A],
			['bob', self::SCHOOL_A],
		]);

		$this->assertSame(1, $removed);
		$this->assertTrue($this->mapper->belongsToSchool('alice', self::SCHOOL_A));
		$this->assertFalse($this->mapper->belongsToSchool('alice', self::SCHOOL_B));
		$this->assertTrue($this->mapper->belongsToSchool('bob', self::SCHOOL_A));
	}

	public function testDeleteNotInEmptyRemovesAll(): void {
		$this->mapper->upsert('alice', self::SCHOOL_A);
		$this->mapper->upsert('alice', self::SCHOOL_B);

		$this->assertSame(2, $this->mapper->deleteNotIn([]));
		$this->assertFalse($this->mapper->contains('alice'));
	}
}
