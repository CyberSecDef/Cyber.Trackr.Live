<?php

namespace App\Tests\Twig;

use App\Twig\AppExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AppExtensionTest extends TestCase
{
    private function ext(): AppExtension
    {
        return new AppExtension();
    }

    public function testRegexReplace(): void
    {
        $this->assertSame('foo#', $this->ext()->regex_replace('foo123', '/\d+/', '#'));
    }

    public function testSha1(): void
    {
        $this->assertSame(sha1('abc'), $this->ext()->sha1('abc'));
    }

    public function testEditorialTitleLowercasesSmallWords(): void
    {
        $this->assertSame('Policy and Procedures', $this->ext()->editorialTitle('POLICY AND PROCEDURES'));
        $this->assertSame(
            'Access Control for Mobile Devices',
            $this->ext()->editorialTitle('ACCESS CONTROL FOR MOBILE DEVICES')
        );
        $this->assertSame('Audit and Accountability', $this->ext()->editorialTitle('AUDIT AND ACCOUNTABILITY'));
    }

    #[DataProvider('freshnessProvider')]
    public function testFreshnessTag(string $offset, string $expected): void
    {
        $this->assertSame($expected, $this->ext()->freshnessTag(new \DateTimeImmutable($offset)));
    }

    public static function freshnessProvider(): array
    {
        // Offsets are well clear of the bucket boundaries (365/1095/1825 days).
        return [
            'recent'   => ['-100 days', 'fresh'],
            'stale'    => ['-700 days', 'stale'],
            'aged'     => ['-1500 days', 'aged'],
            'old'      => ['-3000 days', 'old'],
        ];
    }

    public function testFreshnessTagEmptyOrInvalidIsOld(): void
    {
        $this->assertSame('old', $this->ext()->freshnessTag(null));
        $this->assertSame('old', $this->ext()->freshnessTag(''));
        $this->assertSame('old', $this->ext()->freshnessTag('not a date'));
    }

    #[DataProvider('relTimeProvider')]
    public function testRelTime(string $offset, string $expected): void
    {
        // -12h margin keeps each input safely mid-bucket despite sub-second
        // drift between "now" and the parsed date.
        $this->assertSame($expected, $this->ext()->relTime(new \DateTimeImmutable($offset)));
    }

    public static function relTimeProvider(): array
    {
        return [
            'days'   => ['-3 days -12 hours', '3 days ago'],
            'weeks'  => ['-21 days -12 hours', '3 weeks ago'],
            'months' => ['-90 days -12 hours', '3 months ago'],
            'years'  => ['-730 days -12 hours', '2.0 years ago'],
        ];
    }

    public function testRelTimeEmptyOrInvalidIsUnknown(): void
    {
        $this->assertSame('unknown', $this->ext()->relTime(null));
        $this->assertSame('unknown', $this->ext()->relTime(''));
        $this->assertSame('unknown', $this->ext()->relTime('not a date'));
    }
}
