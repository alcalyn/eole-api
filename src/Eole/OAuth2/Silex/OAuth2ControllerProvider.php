<?php

namespace Eole\OAuth2\Silex;

use Pimple\ServiceProviderInterface;
use Pimple\Container;
use Silex\Api\ControllerProviderInterface;
use Silex\Application;
use Eole\OAuth2\Controller\OAuth2Controller;

class OAuth2ControllerProvider implements ControllerProviderInterface, ServiceProviderInterface
{
    /**
     * {@inheritDoc}
     */
    public function register(Container $app)
    {
        $app['eole.oauth.controller'] = function () use ($app) {
            return new OAuth2Controller($app['eole.oauth.authorization_server']);
        };
    }

    /**
     * {@inheritDoc}
     */
    public function connect(Application $app)
    {
        $controllers = $app['controllers_factory'];

        $controllers->post('/access-token', 'eole.oauth.controller:postAccessToken');

        return $controllers;
    }
}
