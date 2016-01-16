<?php

namespace Eole\RestApi;

use Eole\Silex\Application as BaseApplication;

class Application extends BaseApplication
{
    /**
     * {@InheritDoc}
     */
    public function __construct(array $values = array())
    {
        parent::__construct($values);

        $this->registerServices();
        $this->registerEventListeners();
        $this->mountApi();
    }

    /**
     * Register RestApi services
     */
    private function registerServices()
    {
        $this['eole.listener.api_response_filter'] = function () {
            return new \Eole\Core\EventListener\ApiResponseFilterListener(
                $this['serializer'],
                $this['serializer.context_factory']
            );
        };

        $this['eole.listener.event_to_socket'] = function () {
            return new EventListener\EventToSocketListener(
                $this['eole.push_server'],
                $this['eole.event_serializer'],
                $this['environment']['push_server']['enabled']
            );
        };

        $this['eole.converter.game'] = function () {
            return new \Eole\Core\Converter\GameConverter(
                $this['orm.em']->getRepository('Eole:Game')
            );
        };

        $this['eole.converter.party'] = function () {
            return new \Eole\Core\Converter\PartyConverter(
                $this['orm.em']->getRepository('Eole:Party')
            );
        };

        $this['eole.controller.player'] = function () {
            return new \Eole\Core\Controller\PlayerController(
                $this['eole.player_api'],
                $this['eole.player_manager']
            );
        };

        $this['eole.controller.game'] = function () {
            return new \Eole\Core\Controller\GameController(
                $this['orm.em']->getRepository('Eole:Game')
            );
        };

        $this['eole.controller.party'] = function () {
            return new \Eole\Core\Controller\PartyController(
                $this['orm.em']->getRepository('Eole:Party'),
                $this['orm.em'],
                $this['eole.party_manager'],
                $this['dispatcher']
            );
        };

        $this['eole.push_server'] = function () {
            $pushServerPort = $this['environment']['push_server']['server']['port'];

            $context = new \ZMQContext();
            $socket = $context->getSocket(\ZMQ::SOCKET_PUSH);
            $socket->connect('tcp://127.0.0.1:'.$pushServerPort);

            return $socket;
        };

        $this->before(function (\Symfony\Component\HttpFoundation\Request $request, BaseApplication $app) {
            if (null !== $app['user']) {
                $app['eole.controller.player']->setLoggedUser($app['user']);
                $app['eole.controller.party']->setLoggedPlayer($app['user']);
            }
        });
    }

    private function registerEventListeners()
    {
        $this->on(\Symfony\Component\HttpKernel\KernelEvents::VIEW, function ($event) {
            $this['eole.listener.api_response_filter']->onKernelView($event);
        });

        $this->forwardEventToPushServer(\Eole\Core\Event\PartyEvent::CREATE_AFTER);
        $this->forwardEventToPushServer(\Eole\Core\Event\SlotEvent::JOIN_AFTER);
    }

    /**
     * Automatically forward rest API event to push server.
     *
     * @param string $eventName
     *
     * @return self
     */
    public function forwardEventToPushServer($eventName)
    {
        $this->before(function () use ($eventName) {
            $this['dispatcher']->addListener(
                $eventName,
                array($this['eole.listener.event_to_socket'], 'sendEventToSocket')
            );
        });

        return $this;
    }

    /**
     * Automatically forward rest API events to push server.
     *
     * @param string[] $eventsNames
     *
     * @return self
     */
    public function forwardEventsToPushServer(array $eventsNames)
    {
        foreach ($eventsNames as $eventName) {
            $this->forwardEventToPushServer($eventName);
        }

        return $this;
    }

    /**
     * Mount /api
     */
    private function mountApi()
    {
        $this->mount('api', new ControllerProvider\PlayerControllerProvider());
        $this->mount('api', new ControllerProvider\GameControllerProvider());
        $this->mount('api', new ControllerProvider\PartyControllerProvider());
    }

    /**
     * Mount a game controller provider.
     * If the provider also implements ServiceProviderInterface, it is registered.
     *
     * @param string $gameName
     *
     * @return self
     */
    private function mountGame($gameName)
    {
        $gameConfig = $this['environment']['games'][$gameName];

        if (isset($gameConfig['controller_provider'])) {
            $controllerProviderClass = $gameConfig['controller_provider'];
            $controllerProvider = new $controllerProviderClass();

            if ($controllerProvider instanceof \Pimple\ServiceProviderInterface) {
                $this->register($controllerProvider);
            }

            if (!$controllerProvider instanceof \Silex\Api\ControllerProviderInterface) {
                throw new \LogicException(sprintf(
                    'Game controller provider class (%s) for game %s must implement %s.',
                    $controllerProviderClass,
                    $gameName,
                    'Pimple\\ServiceProviderInterface'
                ));
            }

            $this->mount('api/games/'.$gameName, $controllerProvider);
        }

        return $this;
    }

    /**
     * @param string $gameName
     */
    public function loadGame($gameName)
    {
        parent::loadGame($gameName);

        $this->mountGame($gameName);

        return $this;
    }
}
