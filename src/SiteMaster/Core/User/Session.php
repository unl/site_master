<?php
namespace SiteMaster\Core\User;

use SiteMaster\Core\Events\GetAuthenticationPlugins;
use SiteMaster\Core\Plugin\PluginManager;
use SiteMaster\Core\RequiredLoginException;
use SiteMaster\Core\Util;

class Session
{
    protected static $session;

    public static function logIn(User $user, $auth_plugin_provider_name)
    {
        $session = self::getSession();
        $session->start();

        $session->set('user.id', $user->id);
        $session->set('user.auth_plugin_provider_name', $auth_plugin_provider_name);
        
        //Update user data
        $user->total_logins = $user->total_logins+1;
        $user->last_login = Util::epochToDateTime();
        
        $user->save();
    }

    public static function logOut()
    {
        $session = self::getSession();
        $session->clear();
        $session->invalidate();
    }

    /**
     * Get the currently logged in user
     * 
     * @return bool|\SiteMaster\Core\User\User
     */
    public static function getCurrentUser()
    {
        $session = self::getSession();

        return User::getByID($session->get('user.id'));
    }

    /**
     * Require login
     * 
     * @throws \SiteMaster\Core\RequiredLoginException
     */
    public static function requireLogin()
    {
        if (!self::getCurrentUser()) {
            throw new RequiredLoginException("You must be logged in to access this", 401);
        }
    }
    
    public static function start()
    {
        $session = self::getSession();
        return $session->start();
    }

    public static function getSession()
    {
        if (!self::$session) {
            self::$session = new \Symfony\Component\HttpFoundation\Session\Session();
        }

        return self::$session;
    }

    /**
     * Determine if the session has been started
     * 
     * @return bool
     */
    public static function isSessionStarted()
    {
        return self::$session !== null;
    }

    /**
     * Get the provider name of the authentication plugin that was used to authenticate the current user
     * 
     * @return mixed
     */
    public static function getCurrentAuthProviderPlugin()
    {
        $session = self::getSession();

        $authPlugins = PluginManager::getManager()->dispatchEvent(
            GetAuthenticationPlugins::EVENT_NAME,
            new GetAuthenticationPlugins()
        );

        $auth_provider = $session->get('user.auth_plugin_provider_name');

        foreach ($authPlugins->getPlugins() as $plugin) {
            if ($plugin->getProviderMachineName() == $auth_provider) {
                return $plugin;
            }
        }
    }
}