<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2014-2023, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal;

use RuntimeException;
use Vpn\Portal\Exception\LdapClientException;

class LdapClient
{
    /** @var resource */
    private $ldapResource;

    public function __construct(string $ldapUri)
    {
        if (false === \extension_loaded('ldap')) {
            throw new RuntimeException('"ldap" PHP extension not available');
        }
        $this->ldapResource = ldap_connect($ldapUri);
        if (false === $this->ldapResource) {
            // only with very old OpenLDAP will it ever return false...
            throw new LdapClientException(sprintf('unacceptable LDAP URI "%s"', $ldapUri));
        }
        if (false === ldap_set_option($this->ldapResource, LDAP_OPT_PROTOCOL_VERSION, 3)) {
            throw new LdapClientException('unable to set LDAP option');
        }
        if (false === ldap_set_option($this->ldapResource, LDAP_OPT_REFERRALS, 0)) {
            throw new LdapClientException('unable to set LDAP option');
        }
    }

    /**
     * Bind to an LDAP server.
     *
     * @param ?string $bindUser you MUST use LdapClient::escapeDn on any user input used to contruct the DN!
     */
    public function bind(?string $bindUser = null, ?string $bindPass = null): void
    {
        if (false === ldap_bind($this->ldapResource, $bindUser, $bindPass)) {
            throw new LdapClientException(sprintf('LDAP error: (%d) %s', ldap_errno($this->ldapResource), ldap_error($this->ldapResource)));
        }
    }

    public static function escapeDn(string $str): string
    {
        // ldap_escape in PHP >= 5.6 (or symfony/polyfill-php56)
        return ldap_escape($str, '', LDAP_ESCAPE_DN);
    }

    /**
     * @param array<string> $attributeList
     */
    public function search(string $baseDn, string $searchFilter, array $attributeList = []): array
    {
        $searchResource = ldap_search(
            $this->ldapResource,    // link_identifier
            $baseDn,                // base_dn
            $searchFilter,          // filter
            $attributeList,         // attributes (dn is always returned...)
            0,                      // attrsonly
            0,                      // sizelimit
            10                      // timelimit
        );
        if (false === $searchResource) {
            throw new LdapClientException(sprintf('LDAP error: (%d) %s', ldap_errno($this->ldapResource), ldap_error($this->ldapResource)));
        }
        if (is_array($searchResource)) {
            // ldap_search can return array when doing parallel search, as we
            // don't do that this should not occur, but just making sure and
            // to silence vimeo/psalm
            // @see https://www.php.net/ldap_search
            throw new LdapClientException('multiple results returned, expecting only one');
        }

        $ldapEntries = ldap_get_entries($this->ldapResource, $searchResource);
        if (false === $ldapEntries) {
            throw new LdapClientException(sprintf('LDAP error: (%d) %s', ldap_errno($this->ldapResource), ldap_error($this->ldapResource)));
        }

        return $ldapEntries;
    }
}
