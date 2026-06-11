<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Per-IP rate limiting for the public JSON API.
 *
 * Applies the 'api' limiter (120 req/min/IP, sliding window — see
 * config/packages/rate_limiter.yaml) to every /api request. Invisible to humans
 * and normal API clients; throttles bulk crawlers. Returns 429 + Retry-After
 * when the window is exhausted.
 *
 * Keyed by client IP, which is accurate on DreamHost where Apache serves PHP
 * directly. If a CDN/reverse proxy is ever placed in front, configure
 * framework.trusted_proxies so getClientIp() reads the forwarded address rather
 * than the proxy's (otherwise every visitor shares one bucket).
 */
final class ApiRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $apiLimiter,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        // Never throttle loopback — the server's own internal calls, health
        // checks, and the functional-test client all originate from here.
        $ip = $request->getClientIp();
        if (null === $ip || '127.0.0.1' === $ip || '::1' === $ip) {
            return;
        }

        $limit = $this->apiLimiter->create($ip)->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retryAfter, 'API rate limit exceeded — slow down.');
        }
    }

    public static function getSubscribedEvents(): array
    {
        // Run early, before routing/controller work, so throttled requests are
        // cheap to reject.
        return [KernelEvents::REQUEST => [['onKernelRequest', 100]]];
    }
}
