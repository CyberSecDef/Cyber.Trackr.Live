<?php

namespace App\Tests\Service\Plans;

use App\Service\Plans\Renderer\DocxRenderer;
use PHPUnit\Framework\TestCase;

class DocxRendererTest extends TestCase
{
    private const PLAN = ['system' => ['system_name' => 'Test System'], 'family' => 'ac', 'controls' => []];

    /** @return list<string> */
    private function leftovers(): array
    {
        return glob(sys_get_temp_dir() . '/plan_*') ?: [];
    }

    // Review S19: tempnam() created plan_XXXX, the DOCX was written to
    // plan_XXXX.docx, and only the .docx was deleted, leaking one empty file
    // per plan download.
    public function testRenderingLeavesNoTempFilesBehind(): void
    {
        $before = $this->leftovers();
        $renderer = new DocxRenderer();
        for ($i = 0; $i < 3; $i++) {
            $docx = $renderer->renderToString(self::PLAN);
            $this->assertStringStartsWith('PK', $docx, 'a .docx is a ZIP');
        }
        $this->assertSame($before, $this->leftovers());
    }
}
