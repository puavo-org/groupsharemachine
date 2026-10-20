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
			'Groups:   seen=%d kept=%d pruned=%d%s',
			$stats['groups']['seen'],
			$stats['groups']['kept'],
			$stats['groups']['pruned'],
			$stats['groups']['reason'] === null ? '' : '  PRUNE SKIPPED: ' . $stats['groups']['reason'],
		));
		$output->writeln(sprintf(
			'Teachers: seen=%d kept=%d pruned=%d%s',
			$stats['teachers']['seen'],
			$stats['teachers']['kept'],
			$stats['teachers']['pruned'],
			$stats['teachers']['reason'] === null ? '' : '  PRUNE SKIPPED: ' . $stats['teachers']['reason'],
		));

		if ($stats['groups']['complete'] && $stats['teachers']['complete']) {
			return self::SUCCESS;
		}

		// Non-zero so a cron wrapper or deploy script notices. Whatever the
		// reason, the tables were left exactly as the last good run wrote them
		// rather than pruned against a result we don't trust, so sharing keeps
		// working on yesterday's data until this is sorted out.
		$output->writeln('');
		$output->writeln('<error>Nothing was pruned: this run did not produce a trustworthy picture of LDAP.</error>');
		return self::FAILURE;
	}
}
