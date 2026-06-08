<?php

namespace App\Tests\Service\Search;

use App\Service\Search\QueryParser;
use App\Service\Search\Tokenizer;
use PHPUnit\Framework\TestCase;

class QueryParserTest extends TestCase
{
    private function parser(): QueryParser
    {
        // Uses the real Tokenizer — no mock needed, it's pure.
        return new QueryParser(new Tokenizer());
    }

    public function testBareTermsBecomeTokensWithNoPhrases(): void
    {
        $r = $this->parser()->parse('windows server');
        $this->assertSame([], $r['phrases']);
        $this->assertSame(['windows', 'server'], $r['tokens']);
    }

    public function testQuotedRunIsCapturedAsPhrase(): void
    {
        $r = $this->parser()->parse('"access control"');
        $this->assertSame(['access control'], $r['phrases']);
        // Phrase terms also join the AND token set.
        $this->assertSame(['access', 'control'], $r['tokens']);
    }

    public function testMixedBareAndPhrase(): void
    {
        $r = $this->parser()->parse('firewall "access control"');
        $this->assertSame(['access control'], $r['phrases']);
        $this->assertSame(['firewall', 'access', 'control'], $r['tokens']);
    }

    public function testPhrasesAreLowercasedAndTrimmed(): void
    {
        $r = $this->parser()->parse('"  Access Control  "');
        $this->assertSame(['access control'], $r['phrases']);
    }

    public function testPhraseTokensAreNotDuplicatedInTokenSet(): void
    {
        $r = $this->parser()->parse('access "access control"');
        $this->assertSame(['access', 'control'], $r['tokens']);
    }

    public function testEmptyQueryYieldsEmptyResult(): void
    {
        $r = $this->parser()->parse('');
        $this->assertSame(['phrases' => [], 'tokens' => []], $r);
    }
}
