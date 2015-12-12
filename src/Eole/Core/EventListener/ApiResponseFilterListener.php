<?php

namespace Eole\Core\EventListener;

use JMS\Serializer\SerializerInterface;
use JMS\Serializer\SerializationContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Eole\Core\ApiResponse;

class ApiResponseFilterListener
{
    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var callable
     */
    private $contextFactory;

    /**
     * @var string
     */
    private $defaultResponseFormat;

    /**
     * @param SerializerInterface $serializer
     * @param callable $contextFactory
     * @param string $defaultResponseFormat
     */
    public function __construct(
        SerializerInterface $serializer,
        callable $contextFactory,
        $defaultResponseFormat = 'json'
    ) {
        $this->serializer = $serializer;
        $this->contextFactory = $contextFactory;
        $this->defaultResponseFormat = $defaultResponseFormat;
    }

    /**
     * @return SerializationContext
     */
    private function createContext()
    {
        $contextFactory = $this->contextFactory;

        return $contextFactory();
    }

    /**
     * @param GetResponseForControllerResultEvent $event
     */
    public function onKernelView(GetResponseForControllerResultEvent $event)
    {
        if (!$event->isMasterRequest()) {
            return;
        }

        $apiResponse = $event->getControllerResult();

        if (!($apiResponse instanceof ApiResponse)) {
            return;
        }

        $format = $event->getRequest()->getRequestFormat($this->defaultResponseFormat);
        $serialized = $this->serializer->serialize($apiResponse->getData(), $format, self::createContext());
        $response = new Response($serialized, $apiResponse->getStatusCode());

        $response->headers->set('Content-Type', $event->getRequest()->getMimeType($format));

        $event->setResponse($response);
    }
}