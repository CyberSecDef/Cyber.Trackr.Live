<?php

namespace App\EventSubscriber;

use App\Service\DataVersion;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * HTTP caching for the pages and the public JSON API (review P10/P11).
 *
 * Everything the site serves is built from the bundled data files, which
 * change at most daily (bin/refresh-data.sh) or on deploy. The ETag is
 * therefore derived from DataVersion (code + data mtimes, plus today's date)
 * and the URL, so it is known BEFORE any work is done:
 *
 *  - kernel.request (after routing and both rate limiters): a request whose
 *    If-None-Match matches gets an empty 304 without running the controller,
 *    rendering a template, or parsing any XML.
 *  - kernel.response: 200 HTML/JSON responses carry that ETag.
 *      /api/*  public, max-age=3600 (as before): ingest clients re-pull often.
 *      pages   public, no-cache: always revalidated, so a refresh or deploy
 *              shows up on the next visit, but an unchanged page costs a 304.
 *    An explicit Expires is always sent. DreamHost's server-wide mod_expires
 *    otherwise appends its own Cache-Control (max-age=600 for HTML, 172800
 *    for JSON) next to ours; it leaves responses that have one alone.
 *
 * Not cached:
 *  - /api/stig/tip returns a RANDOM tip per call.
 *  - AI mitigation requests (?m=): the statement and its rate-limit status
 *    are not part of the data files. A successful one keeps the old
 *    body-hash ETag; a rate-limited one is already no-store.
 *  - API errors and the 429 from ApiRateLimitSubscriber are no-store so a
 *    stale error or throttle notice is never served back.
 *  - Anything with a Content-Disposition (downloads; skeleton.cklb mints
 *    fresh UUIDs) or that isn't HTML/JSON keeps Symfony's defaults.
 *  - The dev environment, where templates change without a cache:clear.
 */
final class HttpCacheSubscriber implements EventSubscriberInterface
{
    /** Freshness window for cacheable API responses, in seconds (1 hour). */
    private const API_MAX_AGE = 3600;

    private const ETAG = '_data_etag';

    public function __construct(
        private readonly DataVersion $version,
        #[Autowire('%kernel.environment%')] private readonly string $env,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->versioned($request)) {
            return;
        }

        $etag = '"' . sha1($this->version->page() . "\n" . $request->getRequestUri()) . '"';
        $request->attributes->set(self::ETAG, $etag);

        if ($this->clientHas($request, $etag)) {
            $response = new Response('', Response::HTTP_NOT_MODIFIED);
            $this->cacheable($request, $response, $etag);
            $event->setResponse($response);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $response = $event->getResponse();
        $etag = $request->attributes->get(self::ETAG);
        $isApi = str_starts_with($request->getPathInfo(), '/api');

        if (Response::HTTP_NOT_MODIFIED === $response->getStatusCode() && null !== $etag) {
            return; // our own early 304, already complete
        }

        if ($isApi && ('/api/stig/tip' === $request->getPathInfo()
            || 200 !== $response->getStatusCode()
            || !$request->isMethodCacheable()
            || $response->headers->hasCacheControlDirective('no-store'))
        ) {
            $response->headers->set('Cache-Control', 'no-store');
            $response->setExpires(new \DateTimeImmutable('@0'));
            return;
        }

        if (200 !== $response->getStatusCode()
            || $response->headers->hasCacheControlDirective('no-store')
            || $response->headers->has('Content-Disposition')
            || !preg_match('#^(text/html|application/json)\b#', (string) $response->headers->get('Content-Type'))
        ) {
            return;
        }

        if (null === $etag) {
            if (!$isApi) {
                return;
            }
            // An API reply carrying an AI mitigation (or any API reply in
            // dev): revalidate by content.
            $etag = '"' . hash('sha1', (string) $response->getContent()) . '"';
        }

        $this->cacheable($request, $response, $etag);
        // If the client already holds this exact representation, collapse the
        // body to an empty 304 (only reachable for the body-hash case above).
        $response->isNotModified($request);
    }

    /** Requests whose response is a pure function of URL + DataVersion. */
    private function versioned(Request $request): bool
    {
        return 'dev' !== $this->env
            && $request->isMethodCacheable()
            && '/api/stig/tip' !== $request->getPathInfo()
            && !$request->query->has('m')
            && !str_starts_with($request->getPathInfo(), '/_');
    }

    private function cacheable(Request $request, Response $response, string $etag): void
    {
        $response->setEtag($etag);
        $response->setPublic();
        if (str_starts_with($request->getPathInfo(), '/api')) {
            $response->setMaxAge(self::API_MAX_AGE);
            $response->setExpires(new \DateTimeImmutable('+' . self::API_MAX_AGE . ' seconds'));
        } else {
            $response->headers->addCacheControlDirective('no-cache');
            $response->setExpires(new \DateTimeImmutable());
        }
        // Keep gzip/identity variants distinct in any shared cache.
        $response->setVary('Accept-Encoding', false);
    }

    /**
     * If-None-Match contains our ETag. Apache's mod_deflate appends "-gzip"
     * to the ETag of compressed responses and the browser echoes that back,
     * so the suffix is ignored, as is a weak (W/) prefix.
     */
    private function clientHas(Request $request, string $etag): bool
    {
        $header = (string) $request->headers->get('If-None-Match', '');
        if ($header === '') {
            return false;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = preg_replace(['#^W/#', '#-(gzip|br|deflate)"$#'], ['', '"'], trim($candidate));
            if ($candidate === $etag) {
                return true;
            }
        }
        return false;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After RouterListener (32), ApiRateLimitSubscriber (100) and
            // RouteRateLimitSubscriber (30): a throttled client still gets 429.
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
            // After Symfony's ResponseListener (0), which fills in the default
            // Content-Type: text/html this checks for.
            KernelEvents::RESPONSE => [['onKernelResponse', -10]],
        ];
    }
}
