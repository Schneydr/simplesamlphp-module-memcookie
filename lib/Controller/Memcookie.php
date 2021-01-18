<?php

declare(strict_types=1);

namespace SimpleSAML\Module\memcookie\Controller;

use SimpleSAML\Auth;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Module\memcookie\AuthMemCookie;
use SimpleSAML\Session;
use SimpleSAML\Utils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller class for the memcookie module.
 *
 * This class serves the different views available in the module.
 *
 * @package simplesamlphp/simplesamlphp-module-memcookie
 */
class Memcookie
{
    /** @var \SimpleSAML\Configuration */
    protected $config;

    /** @var \SimpleSAML\Session */
    protected $session;


    /**
     * Controller constructor.
     *
     * It initializes the global configuration and session for the controllers implemented here.
     *
     * @param \SimpleSAML\Configuration $config The configuration to use by the controllers.
     * @param \SimpleSAML\Session $session The session to use by the controllers.
     *
     * @throws \Exception
     */
    public function __construct(
        Configuration $config,
        Session $session
    ) {
        $this->config = $config;
        $this->session = $session;
    }


    /**
     * This method implements an script which can be used to authenticate users with Auth MemCookie.
     * See: https://zenprojects.github.io/Apache-Authmemcookie-Module/
     *
     * The configuration for this script is stored in config/module_authmemcookie.php.
     *
     * The file extra/auth_memcookie.conf contains an example of how Auth Memcookie can be configured
     * to use SimpleSAMLphp.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @return \SimpleSAML\HTTP\RunnableResponse
     */
    public function main(Request $request): RunnableResponse
    {
        // load SimpleSAMLphp configuration
        $ssp_cf = $this->config::getInstance();

        // load Auth MemCookie configuration
        $amc_cf = AuthMemCookie::getInstance();

        $sourceId = $amc_cf->getAuthSource();
        $s = new Auth\Simple($sourceId);

        // check if the user is authorized. We attempt to authenticate the user if not
        $s->requireAuth();

        // generate session id and save it in a cookie
        $sessionID = Utils\Random::generateID();
        $cookieName = $amc_cf->getCookieName();
        Utils\HTTP::setCookie($cookieName, $sessionID);

        // generate the authentication information
        $attributes = $s->getAttributes();

        $authData = [];

        // username
        $usernameAttr = $amc_cf->getUsernameAttr();
        if ($usernameAttr === null || !array_key_exists($usernameAttr, $attributes)) {
            throw new Error\Exception(
                "The user doesn't have an attribute named '" . $usernameAttr .
                "'. This attribute is expected to contain the username."
            );
        }
        $authData['UserName'] = $attributes[$usernameAttr];

        // groups
        $groupsAttr = $amc_cf->getGroupsAttr();
        if ($groupsAttr !== null) {
            if (!array_key_exists($groupsAttr, $attributes)) {
                throw new Error\Exception(
                    "The user doesn't have an attribute named '" . $groupsAttr .
                    "'. This attribute is expected to contain the groups the user is a member of."
                );
            }
            $authData['Groups'] = $attributes[$groupsAttr];
        } else {
            $authData['Groups'] = [];
        }

        $authData['RemoteIP'] = $request->server->get('REMOTE_ADDR');

        foreach ($attributes as $n => $v) {
            $authData['ATTR_' . $n] = $v;
        }

        // store the authentication data in the memcache server
        $data = '';
        foreach ($authData as $n => $v) {
            if (is_array($v)) {
                $v = implode(':', $v);
            }
            $data .= $n . '=' . $v . "\r\n";
        }

        $memcache = $amc_cf->getMemcache();
        $expirationTime = $s->getAuthData('Expire');
        $memcache->set($sessionID, $data, $expirationTime ?? 0);

        // register logout handler
        $session = $this->session::getSessionFromRequest();
        $session->registerLogoutHandler($sourceId, '\SimpleSAML\Module\memcookie\AuthMemCookie', 'logoutHandler');

        // redirect the user back to this page to signal that the login is completed
        return RunnableResponse([Utils\HTTP::class, 'redirectTrustedURL'], [Utils\HTTP::getSelfURL()]);
    }
}
