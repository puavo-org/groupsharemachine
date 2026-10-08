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
			$message = sprintf(
				'groupsharemachine sync: groups(seen=%d kept=%d pruned=%d complete=%s) teachers(seen=%d kept=%d pruned=%d complete=%s)',
				$stats['groups']['seen'],
				$stats['groups']['kept'],
				$stats['groups']['pruned'],
				$stats['groups']['complete'] ? 'yes' : 'no',
				$stats['teachers']['seen'],
				$stats['teachers']['kept'],
				$stats['teachers']['pruned'],
				$stats['teachers']['complete'] ? 'yes' : 'no',
			);
			if ($stats['groups']['complete'] && $stats['teachers']['complete']) {
				$this->logger->info($message);
			} else {
				// Worth a warning: the tables are stale but intact, and will
				// stay that way until a run completes.
				$this->logger->warning($message . ' — prune skipped, tables left as they were');
			}
		} catch (Throwable $e) {
			$this->logger->error('groupsharemachine sync failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
