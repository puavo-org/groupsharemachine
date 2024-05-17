<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Tuomas Nurmi <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GroupShareMachine\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Util;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'groupsharemachine';

    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void
    {
    }

    public function boot(IBootContext $context): void
    {
        /* @var IEventDispatcher $appEventDispatcher */
        $appEventDispatcher = $context->getAppContainer()->get(IEventDispatcher::class);

        $appEventDispatcher->addListener(LoadAdditionalScriptsEvent::class, function () {
            Util::addScript(self::APP_ID, 'groupsharemachine-groupsharemachine');
        });
    }
}
