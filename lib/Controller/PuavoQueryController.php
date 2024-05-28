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
use OCP\IGroupManager;
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
        IGroupManager $groupManager,
        IConfig $config,
        IAppData $appData,
        ?string $userId
    ) {
        parent::__construct($AppName, $request);
        $this->userId = $userId;
        $this->appData = $appData;
        $this->serverContainer = $serverContainer;
        $this->config = $config;
        $this->groupManager = $groupManager;
    }

    /**
     * @NoAdminRequired
     */
    public function searchNextcloudGroups(string $name): DataResponse
    {
        $groups = $this->groupManager->search($name);
        $groups = array_map(function ($group) {
            return $group->getGID();
        }, $groups);
        return new DataResponse($groups);
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
        if ($ch === false) {
            return new DataResponse([ "error" => "connection initialization problem" ]);
        }

        $user = $this->userId;
        if(ctype_digit($user)) {
            $user = "_by_id/" . $user;
        }
        $url = "https://api.opinsys.fi/v3/users/" . $user;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Host: '. $apihost, "Authorization: Basic " . base64_encode($credentials)));
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response === false) {
            return new DataResponse([ "error" => "connection problem" ]);
        }
        $userresponse = json_decode($response);

        if(isset($userresponse->error)) {
            return new DataResponse([ "error" => $userresponse->error->code ]);
        }

        /////// Two alternative methods for checking if class button list should be displayed.
        // The commented-out one is a bit cleaner, but the other one in use corresponds to the behaviour check added to nextcloud
        // core, so using that ensures that there shouldn't be any surprising permission errors if a teacher/admin account does not
        // belong to a teacher group
        /*
        if(!in_array("teacher", $userresponse->roles) && !in_array("admin", $userresponse->roles)) {
            return new DataResponse([ "warning" => "not teacher, no buttons" ]);
        }*/
        $found = -1;
        if(isset($userresponse->schools)) {
            foreach($userresponse->schools as $thisSchool) {
                foreach($thisSchool->groups as $thisGroup) {
                    if ((stripos($thisGroup->name, "lehrer") !== false || stripos($thisGroup->abbreviation, "lehrer") !== false)) {
                        $found = 1;
                        break;
                    }
                }
            }
        }
        if($found == -1) { // not teacher, show nothing
            return new DataResponse([ "warning" => "not teacher, no buttons" ]);
        }
        ///////// End of class button list enablation check methods

        $primarySchool = $userresponse->primary_school_dn;

        $gurl = "https://api.opinsys.fi/v4/groups?filter=school_id|is|" . $primarySchool . "&fields=abbreviation,type,name,school_id";
        $ch2 = curl_init();
        if ($ch2 === false) {
            return new DataResponse([ "error" => "connection initialization problem" ]);
        }
        curl_setopt($ch2, CURLOPT_URL, $gurl);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, array('Host: '. $apihost, "Authorization: Basic " . base64_encode($credentials)));
        $groupqueryreply = curl_exec($ch2);
        if ($groupqueryreply === false) {
            return new DataResponse([ "error" => "connection problem" ]);
        }
        $gresponse = json_decode($groupqueryreply, true);
        curl_close($ch2);

        if(!$gresponse) {
            return new DataResponse([ "error" => $groupqueryreply ]);
        }

        $groupresponse = array_filter($gresponse['data'], function ($var) {
            return $var["type"] == "teaching group";
        });

        return new DataResponse([ array_values($groupresponse) ]);
    }

}
