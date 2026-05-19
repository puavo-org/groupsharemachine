<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\AppInfo;

use OCA\GroupShareMachine\Collaboration\TeacherClassSearchPlugin;
use OCA\GroupShareMachine\GroupBackend\TeacherClassMembership;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IGroupManager;

class Application extends App implements IBootstrap {

	public const APP_ID = 'groupsharemachine';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (IGroupManager $groupManager, TeacherClassMembership $backend, ISearch $collaboratorSearch): void {
			$groupManager->addBackend($backend);
			$collaboratorSearch->registerPlugin([
				'shareType' => 'SHARE_TYPE_GROUP',
				'class' => TeacherClassSearchPlugin::class,
			]);
		});
	}
}
