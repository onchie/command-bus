<?php

namespace Rezzza\CommandBus\Infra\Provider\Direct;

use Rezzza\CommandBus\Domain\CommandInterface;
use Rezzza\CommandBus\Domain\Consumer\Response;
use Rezzza\CommandBus\Domain\DirectCommandBusInterface;
use Rezzza\CommandBus\Domain\Event;
use Rezzza\CommandBus\Domain\Handler\CommandHandlerLocatorInterface;
use Rezzza\CommandBus\Domain\Handler\HandlerDefinition;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class DirectBus implements DirectCommandBusInterface
{
    private $locator;
    private $eventDispatcher;

    /**
     * @param CommandHandlerLocatorInterface $locator         locator
     * @param EventDispatcherInterface       $eventDispatcher eventDispatcher
     */
    public function __construct(CommandHandlerLocatorInterface $locator, EventDispatcherInterface $eventDispatcher)
    {
        $this->locator         = $locator;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function handle(CommandInterface $command, $priority = null)
    {
        try {
            $this->eventDispatcher->dispatch(Event\Events::PRE_HANDLE_COMMAND, new Event\PreHandleCommandEvent($this, $command));

            $handler = $this->locator->getCommandHandler($command);

            if (is_callable($handler)) {
                $handler($command);
            } elseif (is_object($handler)) {
                $method = null;
                if ($handler instanceof HandlerDefinition) {
                    $method  = $handler->getMethod();
                    $handler = $handler->getObject();
                }

                if (null === $method) {
                    $method  = $this->getHandlerMethodName($command);
                }

                if (!method_exists($handler, $method)) {
                    throw new \RuntimeException(sprintf("Service %s has no method %s to handle command.", get_class($handler), $method));
                }
                $handler->$method($command);

                $this->eventDispatcher->dispatch(Event\Events::ON_DIRECT_RESPONSE, new Event\OnDirectResponseEvent(new Response(
                    $command, Response::SUCCESS
                )));
            } else {
                throw new \LogicException(sprintf('Handler locator return a not object|callable handler, type is %s', gettype($handler)));
            }
        } catch (\Exception $e) {
            $this->eventDispatcher->dispatch(Event\Events::ON_DIRECT_RESPONSE, new Event\OnDirectResponseEvent(new Response(
                $command, Response::FAILED, $e
            )));

            throw $e;
        }
    }

    /**
     * Method \Acme\Foo\Bar\DoActionCommand return doAction.
     *
     * @param CommandInterface $command command
     *
     * @return string
     */
    private function getHandlerMethodName(CommandInterface $command)
    {
        $parts = explode("\\", get_class($command));

        return str_replace("Command", "", lcfirst(end($parts)));
    }
}
