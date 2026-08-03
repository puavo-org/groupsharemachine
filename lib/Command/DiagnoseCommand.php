<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Command;

use OCA\GroupShareMachine\Db\ClassGroupMapper;
use OCA\GroupShareMachine\Db\TeacherMapper;
use OCA\GroupShareMachine\GroupBackend\TeacherClassMembership;
use OCP\IGroupManager;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Answers "why can/can't user X share to group Y?" — prints whether the
 * group is in the class-groups table, whether the user is in the teachers
 * table, and whether the custom backend virtualises membership for the pair.
 */
class DiagnoseCommand extends Command {

	public function __construct(
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private ClassGroupMapper $groupMapper,
		private TeacherMapper $teacherMapper,
		private TeacherClassMembership $backend,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('groupsharemachine:diagnose')
			->setDescription('Print whether the custom backend virtualises group membership for a (user, group) pair.')
			->addArgument('uid', InputArgument::REQUIRED, 'User id (e.g. the teacher trying to share)')
			->addArgument('gid', InputArgument::REQUIRED, 'Group id (the share target)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('uid');
		$gid = (string)$input->getArgument('gid');

		$output->writeln("user:  {$uid}");
		$output->writeln("group: {$gid}");
		$output->writeln('');

		$user = $this->userManager->get($uid);
		$output->writeln('user exists in Nextcloud:           ' . ($user === null ? 'NO' : 'YES'));

		$teacherSchools = $this->teacherMapper->schoolsForTeacher($uid);
		$output->writeln('user is a teacher in:               '
			. ($teacherSchools === [] ? '(no school)' : implode(', ', $teacherSchools)));

		$inTable = $this->groupMapper->contains($gid);
		$output->writeln('group in groupsharemachine_groups:  ' . ($inTable ? 'YES' : 'NO'));
		if ($inTable) {
			$school = $this->groupMapper->getSchoolName($gid);
			$schoolDn = $this->groupMapper->getSchoolDn($gid);
			$output->writeln('  school_name:                      ' . ($school ?? '(none recorded)'));
			$output->writeln('  school_dn:                        ' . ($schoolDn ?? '(none recorded)'));
			if ($schoolDn !== null) {
				$inScope = in_array($schoolDn, $teacherSchools, true);
				$output->writeln('  teacher belongs to that school:   ' . ($inScope ? 'YES' : 'NO'));
			}
		}

		$group = $this->groupManager->get($gid);
		$output->writeln('group resolves via IGroupManager:   ' . ($group === null ? 'NO' : 'YES'));

		$output->writeln('');
		$output->writeln('Decision sources:');
		$output->writeln('  virtualised by this app:          ' . ($this->backend->inGroup($uid, $gid) ? 'YES' : 'NO'));
		if ($user !== null && $group !== null) {
			$output->writeln('  aggregate IGroup::inGroup:        ' . ($group->inGroup($user) ? 'YES (some backend says yes)' : 'NO'));
		}
		$output->writeln('');
		$output->writeln('A share to this group from this user will succeed iff the aggregate is YES.');

		return self::SUCCESS;
	}
}
