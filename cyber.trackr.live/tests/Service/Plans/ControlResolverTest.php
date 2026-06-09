<?php

namespace App\Tests\Service\Plans;

use App\Service\OverlayLoader;
use App\Service\Plans\ControlResolver;
use App\Service\Plans\OdvExtractor;
use PHPUnit\Framework\TestCase;

/**
 * ControlResolver reads the committed 800-53 r5 catalog + CCI list and uses
 * OverlayLoader for baseline membership. These tests run against that real
 * data with robust invariants (not pinned counts, which shift on a catalog
 * refresh).
 */
class ControlResolverTest extends TestCase
{
    private const ENTRY_KEYS = [
        'number', 'title', 'is_enhancement', 'parent', 'in_baseline',
        'statement', 'discussion', 'related', 'ccis', 'odvs',
    ];

    private function projectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    private function overlays(): OverlayLoader
    {
        return new OverlayLoader($this->projectDir());
    }

    /** A baseline that is known to contain AC-1 (derived from the data itself). */
    private function baselineWithAc1(): ?string
    {
        return $this->overlays()->getControlOverlays('AC-1')[0] ?? null;
    }

    public function testResolveReturnsShapedAcEntries(): void
    {
        $baseline = $this->baselineWithAc1();
        if ($baseline === null) {
            $this->markTestSkipped('no overlay contains AC-1 (overlay data unavailable)');
        }

        $resolver = new ControlResolver($this->projectDir(), $this->overlays(), new OdvExtractor());
        $out = $resolver->resolve('ac', $baseline);

        $this->assertNotEmpty($out);
        foreach (self::ENTRY_KEYS as $key) {
            $this->assertArrayHasKey($key, $out[0]);
        }
        // Every returned control belongs to the AC family.
        foreach ($out as $entry) {
            $this->assertStringStartsWith('AC-', $entry['number']);
        }
    }

    public function testAc1IsPresentAndInBaseline(): void
    {
        $baseline = $this->baselineWithAc1();
        if ($baseline === null) {
            $this->markTestSkipped('no overlay contains AC-1 (overlay data unavailable)');
        }

        $out = (new ControlResolver($this->projectDir(), $this->overlays(), new OdvExtractor()))->resolve('ac', $baseline);

        // AC-1 is a base control and sorts first.
        $this->assertSame('AC-1', $out[0]['number']);
        $this->assertFalse($out[0]['is_enhancement']);
        $this->assertTrue($out[0]['in_baseline']);
    }

    public function testUnknownFamilyResolvesToEmpty(): void
    {
        $baseline = $this->baselineWithAc1() ?? 'nist-low';
        $out = (new ControlResolver($this->projectDir(), $this->overlays(), new OdvExtractor()))->resolve('zz', $baseline);
        $this->assertSame([], $out);
    }

    public function testMissingCatalogResolvesToEmpty(): void
    {
        $resolver = new ControlResolver('/nonexistent/data', $this->overlays(), new OdvExtractor());
        $this->assertSame([], $resolver->resolve('ac', 'nist-low'));
    }
}
