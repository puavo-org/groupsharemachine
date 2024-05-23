<?php
/**
 * @copyright Copyright (c) 2024 Opinsys Oy <dev@opinsys.fi>
 *
 * @author Tuomas Nurmi <tuomas.nurmi@opinsys.fi>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 *
 * SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 */

namespace OCA\GroupShareMachine\Controller;

use OCP\Files\IAppData;
use OCP\AppFramework\Http\DataDisplayResponse;

use OCP\IConfig;
use OCP\IServerContainer;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;

use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\Files\FileInfo;


use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\GroupShareMachine\AppInfo\Application;

class PuavoQueryController extends Controller
{
    public function __construct(
        $AppName,
        IRequest $request,
        IServerContainer $serverContainer,
        IConfig $config,
        IAppData $appData,
        ?string $userId
    ) {
        parent::__construct($AppName, $request);
        $this->userId = $userId;
        $this->appData = $appData;
        $this->serverContainer = $serverContainer;
        $this->config = $config;
    }

    /**
     * @NoAdminRequired
     */
    public function getPuavoGroups(): DataResponse
    {
        $apiuser = $this->config->getAppValue(Application::APP_ID, 'apiuser', 'username');
        $apipass = $this->config->getAppValue(Application::APP_ID, 'apipassword', 'password');
        $apihost = $this->config->getAppValue(Application::APP_ID, 'apihost', 'demo.opinsys.fi');
        $credentials = $apiuser . ":" . $apipass;
        $ch = curl_init();

        //$user=$this->userId;
        $user = "tuomasapi";
        $url = "https://api.opinsys.fi/v3/users/" . $user;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Host: '. $apihost, "Authorization: Basic " . base64_encode($credentials)));
        $response = curl_exec($ch);
        curl_close($ch);
        $userresponse = json_decode($response); //TODO handle errors
        $primarySchool = $userresponse->primary_school_dn;

        $gurl = "https://api.opinsys.fi/v4/groups?filter=school_id|is|" . $primarySchool . "&fields=abbreviation,type,name,school_id";
        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $gurl);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, array('Host: '. $apihost, "Authorization: Basic " . base64_encode($credentials)));
        $gresponse = curl_exec($ch2);
        $groupresponse = array_filter(json_decode($gresponse, true)['data'], function ($var) { //TODO handle errors
            return $var["type"] == "teaching group";
        });
        curl_close($ch);

        return new DataResponse([ $groupresponse ]);
    }

}
