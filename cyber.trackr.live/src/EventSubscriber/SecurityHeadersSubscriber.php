<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Security headers on every response the app produces (review S9).
 *
 * Static files never reach PHP, so public/.htaccess (Apache, prod) and
 * docker/nginx.conf (air-gap image) send the same set for those. Keep the
 * three in sync; SecurityHeadersTest checks the .htaccess and nginx copies
 * against this class.
 *
 * The CSP still allows 'unsafe-inline' scripts: 22 inline <script> blocks and
 * ~100 inline on*= handlers would have to move to addEventListener + nonces
 * first. It does stop injected code from loading other scripts, sending data
 * anywhere with fetch/XHR or image beacons, and the site from being framed.
 * Every script is self-hosted (markdown-it moved off jsDelivr in S12), so
 * script-src needs no third-party origin.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public const CSP = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; "
        . "connect-src 'self'; "
        . "worker-src 'self' blob:; "
        . "form-action 'self' https://formspree.io; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self'; "
        . "object-src 'none'";

    public const HEADERS = [
        'Strict-Transport-Security' => 'max-age=31536000',
        'X-Content-Type-Options'    => 'nosniff',
        'Referrer-Policy'           => 'strict-origin-when-cross-origin',
        'X-Frame-Options'           => 'DENY',
        'Permissions-Policy'        => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), serial=(), bluetooth=(), hid=(), midi=(), accelerometer=(), gyroscope=(), magnetometer=()',
        'Content-Security-Policy'   => self::CSP,
    ];

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $headers = $event->getResponse()->headers;
        foreach (self::HEADERS as $name => $value) {
            // Never override a header a controller chose deliberately.
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }
    }

    public static function getSubscribedEvents(): array
    {
        // Low priority: after the profiler toolbar injection and other
        // response listeners have finished with the response.
        return [KernelEvents::RESPONSE => [['onKernelResponse', -256]]];
    }
}
