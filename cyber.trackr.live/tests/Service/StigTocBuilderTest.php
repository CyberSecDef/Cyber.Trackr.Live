<?php

namespace App\Tests\Service;

use App\Service\StigTocBuilder;
use PHPUnit\Framework\TestCase;

/**
 * One toc row per title + version + release. DISA ships some releases under
 * two filenames, re-issues others with edits, and sometimes forgets to bump
 * the release inside a newer file; pages used to pick a row by directory
 * order, which differed between hosts.
 */
class StigTocBuilderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tocb_' . uniqid('', true);
        mkdir($this->dir . '/resources/data/stig', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** A minimal XCCDF that parseStig() reads; $rules makes the bytes differ. */
    private function xml(string $file, string $title, string $version, string $release, string $date, string $released, int $rules = 1): void
    {
        $groups = str_repeat('<Group id="V-1"><Rule id="SV-1" severity="medium"><title>t</title></Rule></Group>', $rules);
        file_put_contents("{$this->dir}/resources/data/stig/$file",
            '<?xml version="1.0"?><Benchmark xmlns="http://checklists.nist.gov/xccdf/1.1">'
            . "<status date=\"$date\">accepted</status><title>$title</title>"
            . "<plain-text id=\"release-info\">Release: $release Benchmark Date: $released</plain-text>"
            . "<version>$version</version>$groups</Benchmark>");
    }

    /** @return array<string, array<int,array>> */
    private function toc(): array
    {
        return (new StigTocBuilder($this->dir))->rebuildAll();
    }

    private function releases(array $rows): array
    {
        return array_map(fn($r) => $r['version'] . '.' . $r['release'] . ' ' . $r['filename'], $rows);
    }

    public function testByteIdenticalCopiesBecomeOneRowWithAnAlias(): void
    {
        $this->xml('U_Router_STIG_V2R6_Manual-xccdf.xml', 'Router', '2', '6', '2023-09-13', '25 Oct 2023');
        $this->xml('U_Router_V2R6_STIG_Manual-xccdf.xml', 'Router', '2', '6', '2023-09-13', '25 Oct 2023');

        $rows = $this->toc()['Router'];
        $this->assertCount(1, $rows);
        $this->assertSame('U_Router_STIG_V2R6_Manual-xccdf.xml', $rows[0]['filename'], 'tie: first by filename');
        $this->assertSame(['U_Router_V2R6_STIG_Manual-xccdf.xml'], $rows[0]['aliases']);
    }

    public function testAReissueOfTheSameReleaseKeepsTheNewestBuild(): void
    {
        $this->xml('U_HMC_STIG_V1R5_Manual-xccdf.xml', 'HMC', '1', '5', '2017-09-28', '20 Jan 2015', 2);
        $this->xml('U_IBM_HMC_V1R5_Manual-xccdf.xml', 'HMC', '1', '5', '2015-01-14', '20 Jan 2015', 1);

        $rows = $this->toc()['HMC'];
        $this->assertSame(['1.5 U_HMC_STIG_V1R5_Manual-xccdf.xml'], $this->releases($rows));
        $this->assertArrayNotHasKey('aliases', $rows[0], 'different content is not an alias');
    }

    public function testANewerFileStillDeclaringTheOldReleaseIsListedUnderItsFilename(): void
    {
        $this->xml('U_Citrix_STIG_V1R3_Manual-xccdf.xml', 'Citrix', '1', '3', '2020-03-27', '24 Apr 2020', 1);
        $this->xml('U_Citrix_STIG_V1R4_Manual-xccdf.xml', 'Citrix', '1', '3', '2025-06-23', '02 Jul 2025', 2);

        $rows = $this->toc()['Citrix'];
        $this->assertSame(['1.4 U_Citrix_STIG_V1R4_Manual-xccdf.xml', '1.3 U_Citrix_STIG_V1R3_Manual-xccdf.xml'], $this->releases($rows));
        $this->assertSame('1.3', $rows[0]['xml_release']);
        $this->assertArrayNotHasKey('xml_release', $rows[1]);
    }

    public function testNoRelabelWhenTheFilenameReleaseExistsOrTheCopyIsIdentical(): void
    {
        // V1R4 already exists, so the mislabelled newer file can't take it.
        $this->xml('U_App_V1R3_Manual-xccdf.xml', 'App', '1', '3', '2020-01-01', '01 Jan 2020', 1);
        $this->xml('U_App_V1R4_Manual-xccdf.xml', 'App', '1', '3', '2021-01-01', '01 Jan 2021', 2);
        $this->xml('U_App_V1R4_Final_Manual-xccdf.xml', 'App', '1', '4', '2021-02-01', '01 Feb 2021', 3);
        // Byte-identical copies under V1R0-1 / V1R1 names: one release, an alias.
        $this->xml('U_Droid_V1R0-1_Manual-xccdf.xml', 'Droid', '1', '1', '2023-09-19', '04 Oct 2023');
        $this->xml('U_Droid_V1R1_Manual-xccdf.xml', 'Droid', '1', '1', '2023-09-19', '04 Oct 2023');

        $toc = $this->toc();
        $this->assertSame(['1.4 U_App_V1R4_Final_Manual-xccdf.xml', '1.3 U_App_V1R4_Manual-xccdf.xml'], $this->releases($toc['App']));
        $this->assertSame(['1.1 U_Droid_V1R0-1_Manual-xccdf.xml'], $this->releases($toc['Droid']));
        $this->assertSame(['U_Droid_V1R1_Manual-xccdf.xml'], $toc['Droid'][0]['aliases']);
    }

    /** Outside duplicate groups filenames are as often wrong as the XML (..._V1R78 is 1.8). */
    public function testASingleFileKeepsItsXmlRelease(): void
    {
        $this->xml('U_DotNet_V1R78_Manual-xccdf.xml', 'DotNet', '1', '8', '2019-01-01', '25 Jan 2019');
        $this->assertSame(['1.8 U_DotNet_V1R78_Manual-xccdf.xml'], $this->releases($this->toc()['DotNet']));
    }

    /** Releases sharing a benchmark date come out newest release first. */
    public function testRowsAreNewestFirstWithSameDayReleasesByNumber(): void
    {
        $this->xml('U_PPT_V1R2_Manual-xccdf.xml', 'PPT', '1', '2', '2014-04-03', '25 Apr 2014', 1);
        $this->xml('U_PPT_V1R3_Manual-xccdf.xml', 'PPT', '1', '3', '2014-04-03', '23 January 2015', 2);
        $this->xml('U_PPT_V1R10_Manual-xccdf.xml', 'PPT', '1', '10', '2014-04-03', '23 January 2016', 3);
        $this->xml('U_PPT_V1R6_Manual-xccdf.xml', 'PPT', '1', '6', '2018-04-04', '27 Apr 2018', 4);

        $this->assertSame(['1.6', '1.10', '1.3', '1.2'],
            array_map(fn($r) => $r['version'] . '.' . $r['release'], $this->toc()['PPT']));
    }
}
