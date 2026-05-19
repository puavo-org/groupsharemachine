<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\BackgroundJob;

use OCA\GroupShareMachine\Service\LdapSync;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

class SyncGroupTypesJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private LdapSync $sync,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
	}

	protected function run(mixed $argument): void {
		try {
			$stats = $this->sync->run();
			$this->logger->info(sprintf(
				'groupsharemachine sync: groups(seen=%d kept=%d pruned=%d) teachers(seen=%d kept=%d pruned=%d)',
				$stats['groups']['seen'],
				$stats['groups']['kept'],
				$stats['groups']['pruned'],
				$stats['teachers']['seen'],
				$stats['teachers']['kept'],
				$stats['teachers']['pruned'],
			));
		} catch (Throwable $e) {
			$this->logger->error('groupsharemachine sync failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
