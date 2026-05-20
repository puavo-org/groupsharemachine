<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\Command;

use OCA\GroupShareMachine\Service\LdapSync;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncGroupTypesCommand extends Command {

	public function __construct(
		private LdapSync $sync,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('groupsharemachine:sync')
			->setDescription('Walk LDAP and refresh the local class-groups and teachers tables.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$stats = $this->sync->run();
		$output->writeln(sprintf(
			'Groups:   seen=%d kept=%d pruned=%d',
			$stats['groups']['seen'],
			$stats['groups']['kept'],
			$stats['groups']['pruned'],
		));
		$output->writeln(sprintf(
			'Teachers: seen=%d kept=%d pruned=%d',
			$stats['teachers']['seen'],
			$stats['teachers']['kept'],
			$stats['teachers']['pruned'],
		));
		return self::SUCCESS;
	}
}
