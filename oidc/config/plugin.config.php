<?php
/**
 * OpenID Connect plugin configuration.
 *
 * PHP version 7.4+
 *
 * @category Config
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * OpenID Connect plugin configuration.
 *
 * @category Config
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
$fog_plugin = [];
$fog_plugin['name'] = 'oidc';
$fog_plugin['description'] = 'Sign in to FOG with an OpenID Connect identity '
    . 'provider (Entra ID, Keycloak, Okta, Google, ...). Unlike the LDAP '
    . 'plugin nothing types a password into FOG: the browser is redirected '
    . 'to the provider and comes back with a signed token. Local password '
    . 'login always remains available.';
$fog_plugin['menuicon'] = 'fa fa-id-badge fa-fw';
$fog_plugin['version'] = '1.6.0';
// The extension points this plugin is built on -- plugin API routes, the
// login-page provider hook, and establishSession() provenance -- landed
// during 1.6 development. There is no earlier FOG it can work on.
$fog_plugin['fog_min'] = '1.6.0';
$fog_plugin['author'] = 'Tom Elliott';
$fog_plugin['homepage'] = 'https://fogproject.org';
