<?php

namespace Eole\Silex;

use Doctrine\DBAL\Tools\Console\Helper\ConnectionHelper;
use Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper;
use Doctrine\ORM\Tools\Console\ConsoleRunner;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Application as ConsoleApplication;
use Eole\Silex\Application as SilexApplication;

class Console extends ConsoleApplication
{
    /**
     * @var SilexApplication
     */
    private $silexApplication;

    /**
     * Console application constructor.
     *
     * @param SilexApplication $silexApplication
     */
    public function __construct(SilexApplication $silexApplication)
    {
        parent::__construct('Eole');

        $this->silexApplication = $silexApplication;
        $this->silexApplication->boot();
        $this->addDefaultOptions();
        $this->registerCommands();
    }

    private function addDefaultOptions()
    {
        $this
            ->getDefinition()
            ->addOption(new InputOption('--env', '-e', InputOption::VALUE_REQUIRED, 'The Environment name.', 'dev'))
        ;

        $this
            ->getDefinition()
            ->addOption(new InputOption('--no-debug', null, InputOption::VALUE_REQUIRED, 'Enable debug mode.', true))
        ;
    }

    private function registerCommands()
    {
        $this->registerDoctrineCommands();
        $this->registerEoleCommands();
        $this->registerSilexComands();
    }

    private function registerDoctrineCommands()
    {
        $em = $this->silexApplication['orm.em'];

        // Register Doctrine ORM commands
        $helperSet = new HelperSet(array(
            'db' => new ConnectionHelper($em->getConnection()),
            'em' => new EntityManagerHelper($em)
        ));

        $this->setHelperSet($helperSet);
        ConsoleRunner::addCommands($this);
    }

    private function registerEoleCommands()
    {
        $api = $this->silexApplication['eole.player_api'];
        $playerManager = $this->silexApplication['eole.player_manager'];

        $this->addCommands(array(
            new \Alcalyn\UserApi\Command\EncodePasswordCommand($playerManager),
            new \Eole\Core\Command\CreatePlayerCommand($api),
            new \Eole\Core\Command\CreateGuestCommand($api),
            new \Eole\Core\Command\RunRepositoryMethodCommand($this->silexApplication['orm.em']),
        ));
    }

    private function registerSilexComands()
    {
        $this->addCommands(array(
            new Command\InstallGamesCommand($this->silexApplication),
        ));
    }
}
