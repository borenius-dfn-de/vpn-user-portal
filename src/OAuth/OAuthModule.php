<?php
/**
 *  Copyright (C) 2016 SURFnet.
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SURFnet\VPN\Portal\OAuth;

use SURFnet\VPN\Common\Http\HtmlResponse;
use SURFnet\VPN\Common\Http\JsonResponse;
use SURFnet\VPN\Common\Http\RedirectResponse;
use SURFnet\VPN\Common\Http\Request;
use SURFnet\VPN\Common\Http\Service;
use SURFnet\VPN\Common\Http\ServiceModuleInterface;
use SURFnet\VPN\Common\TplInterface;

class OAuthModule implements ServiceModuleInterface
{
    /** @var \SURFnet\VPN\Common\TplInterface */
    private $tpl;

    /** @var OAuthServer */
    private $oauthServer;

    public function __construct(TplInterface $tpl, OAuthServer $oauthServer)
    {
        $this->tpl = $tpl;
        $this->oauthServer = $oauthServer;
    }

    public function init(Service $service)
    {
        $service->get(
            '/_oauth/authorize',
            function (Request $request, array $hookData) {
                $userId = $hookData['auth'];

                // ask for approving this client/scope
                return new HtmlResponse(
                    $this->tpl->render(
                        'authorizeOAuthClient',
                        $this->oauthServer->getAuthorize($request->getQueryParameters(), $userId)
                    )
                );
            }
        );

        $service->post(
            '/_oauth/authorize',
            function (Request $request, array $hookData) {
                $userId = $hookData['auth'];

                return new RedirectResponse(
                    $this->oauthServer->postAuthorize(
                        $request->getQueryParameters(),
                        $request->getPostParameters(),
                        $userId
                    ),
                    302
                );
            }
        );

        $service->post(
            '/_oauth/token',
            function (Request $request, array $hookData) {
                return new JsonResponse($this->oauthServer->postToken($request->getPostParameters()));
            }
        );
    }
}
