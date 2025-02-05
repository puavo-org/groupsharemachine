<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Tuomas Nurmi <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Create your routes in here. The name is the lowercase name of the controller
 * without the controller part, the stuff after the hash is the method.
 * e.g. page#index -> OCA\ExternalPortal\Controller\PageController->index()
 *
 * The controller class has to be registered in the application.php file since
 * it's instantiated in there
 */
return [
    'resources' => [
    ],
    'routes' => [
        ['name' => 'puavoQuery#getPuavoGroups', 'url' => '/puavoGroups', 'verb' => 'GET'],
        ['name' => 'puavoQuery#searchNextcloudGroups', 'url' => '/searchNextcloudGroups/{name}', 'verb' => 'GET'],
    ]
];
