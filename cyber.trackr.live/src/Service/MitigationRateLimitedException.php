<?php

namespace App\Service;

/**
 * Thrown by MitigationGenerator when a cache miss would exceed the per-client
 * or site-wide generation limit, so the caller can tell the user to retry
 * later instead of failing silently.
 */
final class MitigationRateLimitedException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Mitigation generation limit reached.');
    }
}
