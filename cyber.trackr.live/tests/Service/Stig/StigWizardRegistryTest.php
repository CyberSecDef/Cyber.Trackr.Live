<?php

namespace App\Tests\Service\Stig;

use App\Service\Stig\StigWizardRegistry;
use PHPUnit\Framework\TestCase;

class StigWizardRegistryTest extends TestCase
{
    private function registry(): StigWizardRegistry
    {
        return new StigWizardRegistry(dirname(__DIR__, 3));
    }

    public function testAsdSchemaIsDiscovered(): void
    {
        $this->assertTrue($this->registry()->has('asd'));
        $schema = $this->registry()->getSchema('asd');
        $this->assertIsArray($schema);
        $this->assertSame('Application_Security_and_Development', $schema['meta']['stig_id']);
    }

    public function testFindMatchesAuthoredMajorVersion(): void
    {
        $match = $this->registry()->findForStig('Application_Security_and_Development', '6');
        $this->assertNotNull($match);
        $this->assertSame('asd', $match['key']);
    }

    public function testFindMatchesEveryV6Release(): void
    {
        // The trigger should light up on any V6 release, not just R4.
        $match = $this->registry()->findForStig('Application_Security_and_Development', '6');
        $this->assertNotNull($match, 'V6 (any release) must match');
    }

    public function testFindRejectsOtherMajorVersion(): void
    {
        $this->assertNull(
            $this->registry()->findForStig('Application_Security_and_Development', '5'),
            'V5 must not match a V6-authored schema'
        );
    }

    public function testFindRejectsOtherTitle(): void
    {
        $this->assertNull($this->registry()->findForStig('Google_Chrome_Current_Windows', '6'));
    }
}
