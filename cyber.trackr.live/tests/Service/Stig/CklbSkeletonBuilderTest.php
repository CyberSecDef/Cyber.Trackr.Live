<?php

namespace App\Tests\Service\Stig;

use App\Service\Stig\CklbSkeletonBuilder;
use PHPUnit\Framework\TestCase;

class CklbSkeletonBuilderTest extends TestCase
{
    private function asdPath(): string
    {
        return dirname(__DIR__, 3) . '/resources/data/stig/U_ASD_STIG_V6R4_Manual-xccdf.xml';
    }

    private function build(): array
    {
        $path = $this->asdPath();
        if (!is_file($path)) {
            $this->markTestSkipped('ASD V6R4 XCCDF not present.');
        }
        return (new CklbSkeletonBuilder())->build($path, ['version' => '6', 'release' => '4']);
    }

    public function testTopLevelShapeMatchesCklb(): void
    {
        $cklb = $this->build();

        foreach (['title', 'id', 'cklb_version', 'classification', 'target_data', 'stigs'] as $key) {
            $this->assertArrayHasKey($key, $cklb, "missing top-level key: $key");
        }
        $this->assertSame('1.0', $cklb['cklb_version']);
        $this->assertSame('UNCLASSIFIED', $cklb['classification']);
        $this->assertIsArray($cklb['stigs']);
        $this->assertCount(1, $cklb['stigs']);

        // target_data carries the editable asset fields the viewer expects.
        $this->assertArrayHasKey('host_name', (array) $cklb['target_data']);
    }

    public function testEveryRuleParsedAndBlank(): void
    {
        $stig = $this->build()['stigs'][0];
        $rules = $stig['rules'];

        $this->assertCount(286, $rules, 'ASD V6R4 has 286 rules');
        $this->assertSame(286, $stig['size']);

        foreach ($rules as $r) {
            $this->assertSame('Not_Reviewed', $r['status'], "skeleton rule {$r['group_id']} must start Not_Reviewed");
            $this->assertSame('', $r['finding_details']);
            $this->assertSame('', $r['comments']);
            $this->assertNotSame('', $r['group_id']);
            $this->assertNotSame('', $r['rule_version']);
        }
    }

    public function testKnownRuleFieldFidelity(): void
    {
        $rules = $this->build()['stigs'][0]['rules'];
        $byId = [];
        foreach ($rules as $r) {
            $byId[$r['group_id']] = $r;
        }

        $this->assertArrayHasKey('V-222387', $byId);
        $r = $byId['V-222387'];
        $this->assertSame('APSC-DV-000010', $r['rule_version']);
        $this->assertSame('medium', $r['severity']);
        $this->assertStringContainsString('CCI-000054', implode(',', $r['ccis']));
        $this->assertNotSame('', $r['discussion']);
        $this->assertNotSame('', $r['check_content']);
        $this->assertNotSame('', $r['fix_text']);
        // The late-numbered rule must be present too.
        $this->assertArrayHasKey('V-265634', $byId);
    }

    public function testUuidsAreUnique(): void
    {
        $rules = $this->build()['stigs'][0]['rules'];
        $uuids = array_column($rules, 'uuid');
        $this->assertCount(count($uuids), array_unique($uuids), 'every rule uuid must be unique');
        foreach ($uuids as $u) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $u);
        }
    }
}
