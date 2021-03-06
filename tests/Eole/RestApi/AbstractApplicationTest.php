<?php

namespace Tests\Eole\RestApi;

use Silex\WebTestCase;
use Eole\Core\Model\Player;
use Eole\Core\Model\Game;
use Eole\Core\Service\PlayerManager;
use Eole\RestApi\Application;
use Tests\Eole\RestApi\stubs\OAuth\ClientStorageMock;
use Tests\Eole\RestApi\stubs\OAuth\ResourceServerMock;

abstract class AbstractApplicationTest extends WebTestCase
{
    /**
     * @var PlayerManager
     */
    protected $playerManager;

    /**
     * @var Game[]
     */
    protected $games;

    /**
     * @var Player
     */
    protected $player;

    /**
     * @var Player
     */
    protected $player2;

    /**
     * {@InheritDoc}
     */
    public function createApplication()
    {
        $app = new Application(array(
            'project.root' => __DIR__.'/../../..',
            'env' => 'test',
            'debug' => true,
        ));

        $app['sandstone.oauth.storage.client'] = function () {
            return new ClientStorageMock();
        };

        $app['sandstone.oauth.resource_server'] = function () use ($app) {
            return new ResourceServerMock(
                $app['sandstone.oauth.storage.session'],
                $app['sandstone.oauth.storage.access_token'],
                $app['sandstone.oauth.storage.client'],
                $app['sandstone.oauth.storage.scope']
            );
        };

        return $app;
    }

    /**
     * {@InheritDoc}
     */
    protected function setUp()
    {
        parent::setUp();

        $this->playerManager = $this->app['eole.player_manager'];

        $this->app['db']->executeQuery('delete from eole_core_player');
        $this->app['db']->executeQuery('delete from eole_core_game');

        $player = new Player();
        $player->setUsername('existing-player');
        $this->playerManager->updatePassword($player, 'good-password');

        $player2 = new Player();
        $player2->setUsername('another-existing-player');
        $this->playerManager->updatePassword($player2, 'good-password');

        $game0 = new Game();
        $game0->setName('game-0');

        $game1 = new Game();
        $game1->setName('game-1');

        $this->games = array($game0, $game1);
        $this->player = $player;
        $this->player2 = $player2;

        $this->app['orm.em']->persist($game0);
        $this->app['orm.em']->persist($game1);
        $this->app['orm.em']->persist($player);
        $this->app['orm.em']->persist($player2);
        $this->app['orm.em']->flush();
    }

    /**
     * {@InheritDoc}
     */
    protected function tearDown()
    {
        parent::tearDown();

        $this->app['db']->executeQuery('delete from eole_core_player');
        $this->app['db']->executeQuery('delete from eole_core_game');
    }

    /**
     * @param string $username
     *
     * @return string
     */
    protected static function createOAuth2Token($username)
    {
        return 'Bearer goodtoken-'.$username;
    }
}
