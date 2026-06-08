<?php

namespace App\Tests\Service\Search;

use App\Service\Search\Tokenizer;
use PHPUnit\Framework\TestCase;

class TokenizerTest extends TestCase
{
    private function tok(): Tokenizer
    {
        return new Tokenizer();
    }

    public function testLowercasesAndSplitsOnWhitespace(): void
    {
        $this->assertSame(['windows', 'server'], $this->tok()->tokenize('Windows Server'));
    }

    public function testDropsStopWords(): void
    {
        $out = $this->tok()->tokenize('the quick brown fox');
        $this->assertNotContains('the', $out);
        $this->assertSame(['quick', 'brown', 'fox'], $out);
    }

    public function testDropsTokensShorterThanTwoChars(): void
    {
        // 'a' and 'in' are stopwords; 'x' is a single char; 'os' survives.
        $this->assertSame(['os'], $this->tok()->tokenize('a in OS x'));
    }

    public function testStructuredIdsAreKeptIntactAndNotSplit(): void
    {
        $this->assertSame(['ac-2(3)'], $this->tok()->tokenize('AC-2(3)'));
        $this->assertSame(['cci-000366'], $this->tok()->tokenize('CCI-000366'));
        $this->assertSame(['v-12345'], $this->tok()->tokenize('V-12345'));
    }

    public function testHyphenatedNonIdAlsoEmitsComponents(): void
    {
        // "windows-server" is not a structured id (letter follows the hyphen),
        // so it indexes as the joined form plus each component.
        $this->assertSame(
            ['windows-server', 'windows', 'server'],
            $this->tok()->tokenize('windows-server')
        );
    }

    public function testDeduplicatesPreservingFirstSeenOrder(): void
    {
        $this->assertSame(['server', 'windows'], $this->tok()->tokenize('server server windows server'));
    }

    public function testNonAlphanumericBecomesWhitespace(): void
    {
        $this->assertSame(['hello', 'world'], $this->tok()->tokenize('hello, world!'));
    }

    public function testEmptyStringYieldsNoTokens(): void
    {
        $this->assertSame([], $this->tok()->tokenize(''));
        $this->assertSame([], $this->tok()->tokenize('   '));
    }

    public function testTrigramsAddBoundaryMarkers(): void
    {
        $this->assertSame(['_ac', 'ac_'], $this->tok()->trigrams('ac'));
        $this->assertSame(['_a_'], $this->tok()->trigrams('a'));
        $this->assertSame(['_cc', 'cci', 'ci_'], $this->tok()->trigrams('cci'));
    }
}
