<?php

namespace Eole\Games\Awale;

use Doctrine\Common\Persistence\ObjectManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Eole\Core\Event\PartyEvent;
use Eole\Core\Event\SlotEvent;
use Eole\Core\Service\PartyManager;
use Eole\Games\Awale\Model\AwaleParty;

class EventListener implements EventSubscriberInterface
{
    /**
     * @var ObjectManager
     */
    private $om;

    /**
     * @var PartyManager
     */
    private $partyManager;

    /**
     * @param ObjectManager $om
     */
    public function __construct(ObjectManager $om, PartyManager $partyManager)
    {
        $this->om = $om;
        $this->partyManager = $partyManager;
    }

    /**
     * @param PartyEvent $event
     */
    public function onPartyCreate(PartyEvent $event)
    {
        if ('awale' !== $event->getParty()->getGame()->getName()) {
            return;
        }

        $awaleParty = AwaleParty::createWithSeedsPerContainer(4);

        $awaleParty->setParty($event->getParty());

        $this->om->persist($awaleParty);
    }

    /**
     * @param SlotEvent $event
     */
    public function onPartyJoin(SlotEvent $event)
    {
        if ('awale' !== $event->getParty()->getGame()->getName()) {
            return;
        }

        if ($this->partyManager->isFull($event->getParty())) {
            $this->partyManager->startParty($event->getParty());
        }

        $this->om->persist($event->getParty());
        $this->om->flush();
    }

    /**
     * {@InheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            PartyEvent::CREATE_BEFORE => 'onPartyCreate',
            SlotEvent::JOIN_AFTER => 'onPartyJoin',
        ];
    }
}
