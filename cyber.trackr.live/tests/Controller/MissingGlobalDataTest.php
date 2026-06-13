<?php

namespace App\Tests\Controller;

use App\Controller\ApiController;
use App\Controller\CciController;
use App\Controller\RmfController;
use App\Service\OverlayLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Exercises the "global reference data unavailable" guards that return 503.
 *
 * The controllers take an injectable $dataDir (defaulting to the bundled
 * resources/data in production). Pointing one at a directory that has none of
 * the global files lets us hit the guard cleanly — the ServiceUnavailableHttp-
 * Exception is thrown before any container-dependent rendering, so a plain
 * unit instantiation (no kernel) is enough.
 */
class MissingGlobalDataTest extends TestCase
{
    private const MISSING = '/nonexistent/cyber-trackr-data-dir';

    public function testCciDataReturns503WhenCciCatalogMissing(): void
    {
        // The CCI page shell is always available; the data endpoint (/cci/data) is
        // what guards the reference data, so the 503 lives there now.
        $this->assertServiceUnavailable(fn() => (new CciController(self::MISSING))->cciData());
    }

    public function testApiCciListReturns503(): void
    {
        $this->assertServiceUnavailable(fn() => (new ApiController(self::MISSING))->api_cci_list());
    }

    public function testApiCciShowReturns503(): void
    {
        $this->assertServiceUnavailable(fn() => (new ApiController(self::MISSING))->api_cci_show('CCI-000001'));
    }

    public function testApiRmfV4ListReturns503(): void
    {
        $this->assertServiceUnavailable(fn() => (new ApiController(self::MISSING))->api_rmfv4_list());
    }

    public function testApiRmfV5ListReturns503(): void
    {
        $this->assertServiceUnavailable(fn() => (new ApiController(self::MISSING))->api_rmf_list());
    }

    public function testRmfV5ViewReturns503(): void
    {
        $overlays = $this->createMock(OverlayLoader::class);
        $this->assertServiceUnavailable(fn() => (new RmfController(self::MISSING))->rmf_v5_view($overlays));
    }

    public function testRmfV4ViewReturns503(): void
    {
        $this->assertServiceUnavailable(fn() => (new RmfController(self::MISSING))->rmf_v4_view());
    }

    /**
     * Assert the callable throws a ServiceUnavailableHttpException that maps to
     * HTTP 503.
     */
    private function assertServiceUnavailable(callable $fn): void
    {
        try {
            $fn();
        } catch (ServiceUnavailableHttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
            return;
        }
        $this->fail('Expected ServiceUnavailableHttpException (503) was not thrown.');
    }
}
