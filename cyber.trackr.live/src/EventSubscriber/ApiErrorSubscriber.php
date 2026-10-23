<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Errors under /api/* are JSON, not the HTML error page (site report F5):
 * {"error": "...", "status": 404}, the same "error" key the IAVM/CVE lookups
 * already return. Covers unknown routes and IDs (404), wrong methods (405,
 * with Allow), the rate limit (429, with Retry-After) and anything else
 * (500). Only an HttpException's own message is shown; any other exception
 * gets the bare status text, so nothing internal leaks.
 *
 * Runs after Symfony's ErrorListener has logged the exception (priority 0)
 * and before it renders the HTML page (-128).
 */
final class ApiErrorSubscriber implements EventSubscriberInterface
{
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $e = $event->getThrowable();
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();
            $message = $e->getMessage();
        } else {
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            $headers = [];
            $message = '';
        }
        if ($message === '' || str_starts_with($message, 'No route found for')) {
            // The router's message echoes the method and URL; say it plainly.
            $message = Response::$statusTexts[$status] ?? 'Error';
        }

        $event->setResponse(new JsonResponse(['error' => $message, 'status' => $status], $status, $headers));
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => [['onKernelException', -64]]];
    }
}
