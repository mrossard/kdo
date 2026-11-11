<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class HtmxFlashSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->headers->has('HX-Request') || !$request->hasSession()) {
            return;
        }

        $response = $event->getResponse();
        if ($response->isRedirect() || $response->headers->has('HX-Redirect')) {
            return;
        }

        $session = $request->getSession();
        if (!$session instanceof Session) {
            return;
        }

        $flashBag = $session->getFlashBag();
        if ($flashBag->peekAll() === []) {
            return;
        }

        $flashes = $flashBag->all();
        $encoded = json_encode($flashes, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            $response->headers->set('X-Kdo-Flashes', $encoded);
        }
    }
}
