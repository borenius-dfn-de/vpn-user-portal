<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2018, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace SURFnet\VPN\Portal;

use DateTime;
use fkooman\SeCookie\SessionInterface;
use SURFnet\VPN\Common\Http\BeforeHookInterface;
use SURFnet\VPN\Common\Http\Exception\HttpException;
use SURFnet\VPN\Common\Http\RedirectResponse;
use SURFnet\VPN\Common\Http\Request;
use SURFnet\VPN\Common\Http\Service;
use SURFnet\VPN\Common\Http\UserInfo;

class SamlAuthenticationHook implements BeforeHookInterface
{
    /** @var \fkooman\SeCookie\SessionInterface */
    private $session;

    /** @var string */
    private $userIdAttribute;

    /** @var bool */
    private $addEntityId;

    /** @var null|string */
    private $entitlementAttribute;

    /** @var array<string,array<string>> */
    private $entitlementAuthnContextMapping;

    /**
     * @param \fkooman\SeCookie\SessionInterface $session
     * @param string                             $userIdAttribute
     * @param bool                               $addEntityId
     * @param null|string                        $entitlementAttribute
     * @param array<string,array<string>>        $entitlementAuthnContextMapping
     */
    public function __construct(SessionInterface $session, $userIdAttribute, $addEntityId, $entitlementAttribute, array $entitlementAuthnContextMapping)
    {
        $this->session = $session;
        $this->userIdAttribute = $userIdAttribute;
        $this->addEntityId = $addEntityId;
        $this->entitlementAttribute = $entitlementAttribute;
        $this->entitlementAuthnContextMapping = $entitlementAuthnContextMapping;
    }

    /**
     * @param \SURFnet\VPN\Common\Http\Request $request
     * @param array                            $hookData
     *
     * @return false|\SURFnet\VPN\Common\Http\RedirectResponse|\SURFnet\VPN\Common\Http\UserInfo
     */
    public function executeBefore(Request $request, array $hookData)
    {
        $whiteList = [
            'POST' => [
                '/_saml/acs',
                '/_logout',
            ],
            'GET' => [
                '/_saml/login',
                '/_saml/logout',
                '/_saml/metadata',
            ],
        ];
        if (Service::isWhitelisted($request, $whiteList)) {
            return false;
        }

        if (!$this->session->has('_saml_auth_assertion')) {
            // user not (yet) authenticated, redirect to "login" endpoint
            return self::getLoginRedirect($request);
        }

        /** @var \fkooman\SAML\SP\Assertion */
        $samlAssertion = $this->session->get('_saml_auth_assertion');
        $idpEntityId = $samlAssertion->getIssuer();
        $samlAttributes = $samlAssertion->getAttributes();

        if (!array_key_exists($this->userIdAttribute, $samlAttributes)) {
            throw new HttpException(sprintf('missing SAML user_id attribute "%s"', $this->userIdAttribute), 500);
        }

        $userId = $samlAttributes[$this->userIdAttribute][0];
        // remove "NameID" XML construction if it is there, e.g. for
        // eduPersonTargetedID
        $userId = strip_tags($userId);

        if ($this->addEntityId) {
            // add the entity ID to the user ID, this is used when we have
            // different IdPs that do not guarantee uniqueness among the used
            // user identifier attribute, e.g. NAME_ID or uid
            $userId = sprintf(
                '%s_%s',
                // strip out all "special" characters from the entityID, just
                // like mod_auth_mellon does
                preg_replace('/__*/', '_', preg_replace('/[^A-Za-z.]/', '_', $idpEntityId)),
                $userId
            );
        }

        // XXX should we get the time of the user authentication from the SAML
        // assertion?
        if ($this->session->has('_saml_auth_time')) {
            $authTime = new DateTime($this->session->get('_saml_auth_time'));
        } else {
            $authTime = new DateTime();
            $this->session->set('_saml_auth_time', $authTime->format(DateTime::ATOM));
        }

        $userAuthnContext = $samlAssertion->getAuthnContext();
        if (null !== $this->entitlementAttribute) {
            $userEntitlements = $samlAttributes[$this->entitlementAttribute];

            // if we got an entitlement that's part of the
            // entitlementAuthnContextMapping we have to make sure we have one of
            // the listed AuthnContexts
            foreach ($this->entitlementAuthnContextMapping as $entitlement => $authnContext) {
                if (\in_array($entitlement, $userEntitlements, true)) {
                    if (!\in_array($userAuthnContext, $authnContext, true)) {
                        // we do not have the required AuthnContext, trigger login
                        // and request the first acceptable AuthnContext
                        return self::getLoginRedirect($request, $authnContext[0]);
                    }
                }
            }
        }

        return new UserInfo($userId, $this->getEntitlementList($idpEntityId, $samlAttributes), $authTime);
    }

    /**
     * @param string                      $idpEntityId
     * @param array<string,array<string>> $samlAttributes
     *
     * @return array<int,string>
     */
    private function getEntitlementList($idpEntityId, array $samlAttributes)
    {
        if (null === $this->entitlementAttribute) {
            return [];
        }
        if (!array_key_exists($this->entitlementAttribute, $samlAttributes)) {
            return [];
        }
        $entitlementList = $samlAttributes[$this->entitlementAttribute];

        // we also add the entityID of the IdP to the "entitlement" to be able
        // to enforce which IdP issued the entitlement. This is useful in the
        // multi-IdP federation context where not every IdP is allowed to claim
        // every entitlement...
        $returnEntitlementList = [];
        foreach ($entitlementList as $e) {
            $returnEntitlementList[] = $e;
            $returnEntitlementList[] = sprintf('%s|%s', $idpEntityId, $e);
        }

        return $returnEntitlementList;
    }

    /**
     * @param \SURFnet\VPN\Common\Http\Request $request
     * @param null|string                      $authnContext
     *
     * @return \SURFnet\VPN\Common\Http\RedirectResponse
     */
    private static function getLoginRedirect(Request $request, $authnContext = null)
    {
        // user not (yet) authenticated, redirect to "login" endpoint
        $returnToQuery = [
            'ReturnTo' => $request->getUri(),
        ];

        if (null !== $authnContext) {
            $returnToQuery['AuthnContext'] = $authnContext;
        }

        return new RedirectResponse(
            sprintf(
                '%s_saml/login?%s',
                $request->getRootUri(),
                http_build_query($returnToQuery)
            )
        );
    }
}
