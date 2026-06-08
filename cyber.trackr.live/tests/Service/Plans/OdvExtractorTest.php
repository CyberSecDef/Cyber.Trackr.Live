<?php

namespace App\Tests\Service\Plans;

use App\Service\Plans\OdvExtractor;
use PHPUnit\Framework\TestCase;

class OdvExtractorTest extends TestCase
{
    private function ext(): OdvExtractor
    {
        return new OdvExtractor();
    }

    public function testExtractsAssignmentAndStripsOrganizationDefined(): void
    {
        $odvs = $this->ext()->extract('Review [Assignment: organization-defined frequency] and act.');
        $this->assertCount(1, $odvs);
        $this->assertSame(OdvExtractor::KIND_ASSIGNMENT, $odvs[0]['kind']);
        $this->assertSame('frequency', $odvs[0]['prompt']);
        $this->assertSame([], $odvs[0]['options']);
        $this->assertSame(0, $odvs[0]['id']);
        $this->assertSame('[Assignment: organization-defined frequency]', $odvs[0]['placeholder']);
    }

    public function testExtractsSelectionOne(): void
    {
        $odvs = $this->ext()->extract('Do [Selection: option a; option b].');
        $this->assertCount(1, $odvs);
        $this->assertSame(OdvExtractor::KIND_SELECTION_ONE, $odvs[0]['kind']);
        $this->assertSame('Choose one', $odvs[0]['prompt']);
        $this->assertSame(['option a', 'option b'], $odvs[0]['options']);
    }

    public function testExtractsSelectionOneOrMore(): void
    {
        $odvs = $this->ext()->extract('[Selection (one or more): a; b; c]');
        $this->assertCount(1, $odvs);
        $this->assertSame(OdvExtractor::KIND_SELECTION_MANY, $odvs[0]['kind']);
        $this->assertSame('Choose one or more', $odvs[0]['prompt']);
        $this->assertSame(['a', 'b', 'c'], $odvs[0]['options']);
    }

    public function testMultipleOdvsGetSequentialIds(): void
    {
        $odvs = $this->ext()->extract(
            'Within [Assignment: organization-defined period] notify [Assignment: organization-defined personnel].'
        );
        $this->assertCount(2, $odvs);
        $this->assertSame([0, 1], [$odvs[0]['id'], $odvs[1]['id']]);
        $this->assertSame('period', $odvs[0]['prompt']);
        $this->assertSame('personnel', $odvs[1]['prompt']);
    }

    public function testPlainTextHasNoOdvs(): void
    {
        $this->assertSame([], $this->ext()->extract('The organization implements access control.'));
    }

    public function testSubstituteFillsProvidedValue(): void
    {
        $text = 'Every [Assignment: organization-defined frequency].';
        $odvs = $this->ext()->extract($text);
        $this->assertSame('Every quarter.', $this->ext()->substitute($text, $odvs, [0 => 'quarter']));
    }

    public function testSubstituteUnfilledShowsToBeCompletedMarker(): void
    {
        $text = 'Every [Assignment: organization-defined frequency].';
        $odvs = $this->ext()->extract($text);
        $this->assertSame(
            'Every [TO BE COMPLETED: frequency].',
            $this->ext()->substitute($text, $odvs, [])
        );
    }

    public function testSubstituteAppliesWrapCallback(): void
    {
        $text = 'Every [Assignment: organization-defined frequency].';
        $odvs = $this->ext()->extract($text);
        $out = $this->ext()->substitute($text, $odvs, [0 => 'quarter'], fn(string $v) => "**$v**");
        $this->assertSame('Every **quarter**.', $out);
    }

    public function testSubstituteJoinsArrayValues(): void
    {
        $text = 'Use [Selection (one or more): a; b].';
        $odvs = $this->ext()->extract($text);
        $this->assertSame('Use a, b.', $this->ext()->substitute($text, $odvs, [0 => ['a', 'b']]));
    }

    public function testTokenizeSplitsIntoTextAndOdvSegments(): void
    {
        $text = 'Pre [Assignment: organization-defined frequency] post';
        $odvs = $this->ext()->extract($text);
        $segments = $this->ext()->tokenize($text, $odvs, [0 => 'X']);

        $this->assertCount(3, $segments);
        $this->assertSame(['type' => 'text', 'value' => 'Pre '], $segments[0]);
        $this->assertSame('odv', $segments[1]['type']);
        $this->assertSame('X', $segments[1]['value']);
        $this->assertTrue($segments[1]['filled']);
        $this->assertSame(['type' => 'text', 'value' => ' post'], $segments[2]);
    }

    public function testTokenizePlainTextIsSingleSegment(): void
    {
        $this->assertSame(
            [['type' => 'text', 'value' => 'just text']],
            $this->ext()->tokenize('just text', [], [])
        );
    }
}
