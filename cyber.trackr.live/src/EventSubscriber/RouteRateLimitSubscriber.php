<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Per-IP rate limiting for the expensive page routes (the JSON API has its
 * own ApiRateLimitSubscriber).
 *
 * - stig_bulk_chunk       → 'bulk_download' limiter (30/hour; review S5)
 * - docker_image_download → 'docker_image' limiter (10/hour; review S5)
 * - search                → 'search' limiter (30/minute; review S13)
 *
 * See config/packages/rate_limiter.yaml. Per-request caps (bulk chunk size,
 * search length/terms) bound each request; this bounds how many an IP can
 * make. Returns 429 + Retry-After when exhausted.
 *
 * Matches on the resolved route name, so it runs after the router (priority
 * 32). Same IP and loopback rules as ApiRateLimitSubscriber.
 */
final class RouteRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $bulkDownloadLimiter,
        private readonly RateLimiterFactoryInterface $dockerImageLimiter,
        private readonly RateLimiterFactoryInterface $searchLimiter,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $factory = match ($request->attributes->get('_route')) {
            'stig_bulk_chunk'       => $this->bulkDownloadLimiter,
            'docker_image_download' => $this->dockerImageLimiter,
            'search'                => $this->searchLimiter,
            default                 => null,
        };
        if (null === $factory) {
            return;
        }

        // Never throttle loopback — the server's own internal calls, health
        // checks, and the functional-test client all originate from here.
        $ip = $request->getClientIp();
        if (null === $ip || '127.0.0.1' === $ip || '::1' === $ip) {
            return;
        }

        $limit = $factory->create($ip)->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retryAfter, 'Rate limit exceeded — try again later.');
        }
    }

    public static function getSubscribedEvents(): array
    {
        // After RouterListener (32) so _route is set; before the controller.
        return [KernelEvents::REQUEST => [['onKernelRequest', 30]]];
    }
}
