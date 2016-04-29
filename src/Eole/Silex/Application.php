<?php

namespace Eole\Silex;

use Silex\Application as BaseApplication;

class Application extends BaseApplication
{
    /**
     * {@InheritDoc}
     */
    public function __construct(array $values = array())
    {
        parent::__construct($values);

        $this->logErrors();
        $this->checkConstants();
        $this->loadEnvironmentParameters();
        $this->registerSilexProviders();
        $this->registerSecurity();
        $this->registerOAuth2Security();
        $this->registerServices();
        $this->registerListeners();
        $this->loadAllGames();
        $this->registerDoctrine();

        if ($this['debug']) {
            $this->enableProfiler();
        }
    }

    /**
     * Check whether application constants are well defined.
     */
    private function checkConstants()
    {
        if (!isset($this['project.root'])) {
            throw new \LogicException('project.root must be defined.');
        }

        if (!isset($this['env'])) {
            throw new \LogicException('env must be defined.');
        }

        $environments = array('dev', 'test', 'prod');

        if (!in_array($this['env'], $environments)) {
            throw new \DomainException('env must be one of: "'.implode('", "', $environments).'".');
        }
    }

    /**
     * Load config/environment.yml or config/environment.yml.dist.
     *
     * @throws Exception if file not found.
     */
    private function loadEnvironmentParameters()
    {
        $parser = new \Symfony\Component\Yaml\Parser();
        $environmentFile = $this['project.root'].'/config/environment.yml';
        $extEnvironmentFile = $this['project.root'].'/config/environment_'.$this['env'].'.yml';

        if (!file_exists($environmentFile)) {
            throw new \LogicException($environmentFile.' not found, unable to load environment parameters.');
        }

        $environment = $parser->parse(file_get_contents($environmentFile));

        if (file_exists($extEnvironmentFile)) {
            $extEnvironment = $parser->parse(file_get_contents($extEnvironmentFile));
            $environment = array_replace_recursive($environment, $extEnvironment);
        }

        $this['environment'] = $environment;
    }

    /**
     * Register default silex providers
     */
    private function registerSilexProviders()
    {
        $this->register(new \Silex\Provider\ServiceControllerServiceProvider());
        $this->register(new \Silex\Provider\MonologServiceProvider(), array(
            'monolog.name' => 'eole',
            'monolog.logfile' => $this['project.root'].'/var/logs/monolog_'.$this['env'].'.log',
        ));
    }

    /*
     * Register Symfony security
     */
    private function registerSecurity()
    {
        $userProvider = function () {
            return new \Alcalyn\UserApi\Security\UserProvider($this['eole.player_api']);
        };

        $this->register(new \Silex\Provider\SecurityServiceProvider(), array(
            'security.firewalls' => array(
                'api' => array(
                    'pattern' => '^/api',
                    'oauth' => true,
                    'stateless' => true,
                    'anonymous' => true,
                    'users' => $userProvider,
                ),
            ),
        ));

        $this['eole.player_api'] = function () {
            return new \Eole\Core\Service\PlayerApi(
                $this['eole.player_manager'],
                $this['orm.em']->getRepository('Eole:Player')
            );
        };

        $this['security.default_encoder'] = function () {
            return $this['security.encoder.digest'];
        };

        $this['eole.user_provider'] = $userProvider;
    }

    /*
     * Register OAuth2 Security
     */
    private function registerOAuth2Security()
    {
        $tokensDir = $this['project.root'].'/var/oauth-tokens';
        $clients = $this['environment']['oauth']['clients'];

        $this->register(new \Eole\OAuth2\Silex\OAuth2ServiceProvider('api', $tokensDir, $clients));
    }

    /**
     * Register doctrine DBAL and ORM
     */
    private function registerDoctrine()
    {
        $this->registerDoctrineDBAL();
        $this->registerDoctrineORM();
    }

    /**
     * Register doctrine DBAL
     */
    private function registerDoctrineDBAL()
    {
        $this->register(new \Silex\Provider\DoctrineServiceProvider(), array(
            'db.options' => $this['environment']['database']['connection'],
        ));
    }

    /**
     * Register and configure doctrine ORM
     */
    private function registerDoctrineORM()
    {
        $this->register(new \Dflydev\Provider\DoctrineOrm\DoctrineOrmServiceProvider(), array(
            'orm.proxies_dir' => $this['project.root'].'/var/cache/doctrine/proxies',
            'orm.auto_generate_proxies' => $this['environment']['database']['orm']['auto_generate_proxies'],
            'orm.em.options' => array(
                'mappings' => $this['eole.mappings'],
            ),
        ));
    }

    /**
     * Enable profiler interface for debugging.
     *
     * @throws \LogicException If composer dev dependencies are not loaded.
     */
    private function enableProfiler()
    {
        if (!class_exists('\Silex\Provider\HttpFragmentServiceProvider', true)) {
            throw new \LogicException('You must load composer dev dependencies in order to use profiler.');
        }

        $this->register(new \Silex\Provider\HttpFragmentServiceProvider());
        $this->register(new \Silex\Provider\TwigServiceProvider());

        $this->register(new \Silex\Provider\WebProfilerServiceProvider(), array(
            'profiler.cache_dir' => $this['project.root'].'/var/cache/profiler',
            'profiler.mount_prefix' => '/_profiler',
        ));

        $this->register(new \Sorien\Provider\DoctrineProfilerServiceProvider());
    }

    /**
     * Register Eole services
     */
    private function registerServices()
    {
        $this['serializer.context_factory'] = $this->protect(function () {
            return \JMS\Serializer\SerializationContext::create()
                ->setSerializeNull(true)
            ;
        });

        $this['serializer.builder'] = function () {
            $namingStrategy = new \JMS\Serializer\Naming\SerializedNameAnnotationStrategy(
                new \JMS\Serializer\Naming\CamelCaseNamingStrategy()
            );

            return
                \JMS\Serializer\SerializerBuilder::create()
                ->addMetadataDir($this['project.root'].'/src/Eole/Core/Serializer')
                ->setCacheDir($this['project.root'].'/var/cache/serializer')
                ->setDebug($this['debug'])
                ->setPropertyNamingStrategy($namingStrategy)
                ->setSerializationVisitor('json', new Serializer\JsonSerializationVisitor($namingStrategy))
                ->configureListeners(function (\JMS\Serializer\EventDispatcher\EventDispatcher $dispatcher) {
                    $dispatcher->addSubscriber(new Serializer\DoctrineProxySubscriber(false));
                })
                ->configureHandlers(function (\JMS\Serializer\Handler\HandlerRegistryInterface $handlerRegistry) {
                    $handlerRegistry->registerSubscribingHandler(new Serializer\DoctrineProxyHandler());
                })
            ;
        };

        $this['serializer'] = function () {
            return $this['serializer.builder']->build();
        };

        $this['eole.mappings'] = function () {
            $mappings = array();

            $mappings []= array(
                'type' => 'yml',
                'namespace' => 'Alcalyn\UserApi\Model',
                'path' => $this['project.root'].'/vendor/alcalyn/doctrine-user-api/Mapping',
            );

            $mappings []= array(
                'type' => 'yml',
                'namespace' => 'Eole\Core\Model',
                'path' => $this['project.root'].'/src/Eole/Core/Mapping',
                'alias' => 'Eole',
            );

            return $mappings;
        };

        $this['eole.player_manager'] = function () {
            $encoderFactory = $this['security.encoder_factory'];
            $userClass = \Eole\Core\Model\Player::class;

            return new \Eole\Core\Service\PlayerManager(
                $encoderFactory,
                $userClass
            );
        };

        $this['eole.party_manager'] = function () {
            return new \Eole\Core\Service\PartyManager(
                $this['dispatcher']
            );
        };

        $this['eole.event_serializer'] = function () {
            return new Service\EventSerializer($this['serializer']);
        };

        $this['eole.listener.authorization_header_fix'] = function () {
            return new \Alcalyn\AuthorizationHeaderFix\AuthorizationHeaderFixListener();
        };
    }

    /**
     * Register events listeners.
     */
    private function registerListeners()
    {
        $this->on(
            \Symfony\Component\HttpKernel\KernelEvents::REQUEST,
            array(
                $this['eole.listener.authorization_header_fix'],
                'onKernelRequest'
            ),
            10
        );
    }

    /**
     * Log errors.
     */
    private function logErrors()
    {
        $this->error(function (\Exception $e) {
            $logFile = $this['project.root'].'/var/logs/errors.txt';
            $message = get_class($e).' '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL.PHP_EOL;
            file_put_contents($logFile, $message, FILE_APPEND);
        });
    }

    /**
     * @param string $gameName
     *
     * @return GameProvider
     */
    public function createGameProvider($gameName)
    {
        $gameProviderClass = $this['environment']['games'][$gameName]['provider'];

        $gameProvider = new $gameProviderClass();

        if (!$gameProvider instanceof GameProvider) {
            throw new \LogicException(sprintf(
                'Game provider class (%s) for game %s must implement %s.',
                get_class($gameProvider),
                $gameName,
                GameProvider::class
            ));
        }

        return $gameProvider;
    }

    /**
     * @param string $gameName
     *
     * @return GameProvider of the loaded game.
     */
    public function loadGame($gameName)
    {
        $gameProvider = $this->createGameProvider($gameName);

        $this->register($gameProvider);

        return $gameProvider;
    }

    /**
     * @return self
     */
    public function loadAllGames()
    {
        $games = $this['environment']['games'];

        foreach ($games as $gameName => $config) {
            $this->loadGame($gameName);
        }

        return $this;
    }
}
