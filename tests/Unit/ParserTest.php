<?php
namespace Tests\Unit;

use App\Parser;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    // ---------------------------------------------------------------
    // parse() — basic tokenisation
    // ---------------------------------------------------------------

    public function testSimpleCommand(): void
    {
        [$cmd, $args] = Parser::parse('echo hello');
        $this->assertSame('echo', $cmd);
        $this->assertSame(['hello'], $args);
    }

    public function testMultipleArgs(): void
    {
        [$cmd, $args] = Parser::parse('echo foo bar baz');
        $this->assertSame(['foo', 'bar', 'baz'], $args);
    }

    public function testSingleQuotesPreserveEverything(): void
    {
        [$cmd, $args] = Parser::parse("echo 'hello world'");
        $this->assertSame(['hello world'], $args);
    }

    public function testSingleQuotesPreserveBackslash(): void
    {
        [$cmd, $args] = Parser::parse("echo 'back\\slash'");
        $this->assertSame(['back\\slash'], $args);
    }

    public function testDoubleQuotesAllowSpaces(): void
    {
        [$cmd, $args] = Parser::parse('echo "hello world"');
        $this->assertSame(['hello world'], $args);
    }

    public function testDoubleQuotesEscapeBackslash(): void
    {
        [$cmd, $args] = Parser::parse('echo "back\\\\slash"');
        $this->assertSame(['back\\slash'], $args);
    }

    public function testDoubleQuotesEscapeQuote(): void
    {
        [$cmd, $args] = Parser::parse('echo "say \\"hi\\""');
        $this->assertSame(['say "hi"'], $args);
    }

    public function testDoubleQuotesOtherBackslashIsLiteral(): void
    {
        [$cmd, $args] = Parser::parse('echo "\\n"');
        $this->assertSame(['\\n'], $args);
    }

    public function testBackslashOutsideQuotes(): void
    {
        [$cmd, $args] = Parser::parse('echo hel\\ lo');
        $this->assertSame(['hel lo'], $args);
    }

    public function testMixedQuoting(): void
    {
        [$cmd, $args] = Parser::parse("echo 'it'\"'\"'s'");
        $this->assertSame(["it's"], $args);
    }

    // ---------------------------------------------------------------
    // parse() — redirection extraction
    // ---------------------------------------------------------------

    public function testStdoutRedirect(): void
    {
        [$cmd, $args, $stdoutFile, $stderrFile, $stdoutMode] = Parser::parse('echo hi > out.txt');
        $this->assertSame('echo', $cmd);
        $this->assertSame(['hi'], $args);
        $this->assertSame('out.txt', $stdoutFile);
        $this->assertSame('w', $stdoutMode);
        $this->assertNull($stderrFile);
    }

    public function testStdoutRedirectExplicit(): void
    {
        [$cmd, $args, $stdoutFile, , $stdoutMode] = Parser::parse('echo hi 1> out.txt');
        $this->assertSame('out.txt', $stdoutFile);
        $this->assertSame('w', $stdoutMode);
    }

    public function testStdoutAppend(): void
    {
        [, , $stdoutFile, , $stdoutMode] = Parser::parse('echo hi >> out.txt');
        $this->assertSame('out.txt', $stdoutFile);
        $this->assertSame('a', $stdoutMode);
    }

    public function testStdoutAppendExplicit(): void
    {
        [, , $stdoutFile, , $stdoutMode] = Parser::parse('echo hi 1>> out.txt');
        $this->assertSame('out.txt', $stdoutFile);
        $this->assertSame('a', $stdoutMode);
    }

    public function testStderrRedirect(): void
    {
        [, , $stdoutFile, $stderrFile, , $stderrMode] = Parser::parse('cmd 2> err.txt');
        $this->assertNull($stdoutFile);
        $this->assertSame('err.txt', $stderrFile);
        $this->assertSame('w', $stderrMode);
    }

    public function testStderrAppend(): void
    {
        [, , , $stderrFile, , $stderrMode] = Parser::parse('cmd 2>> err.txt');
        $this->assertSame('err.txt', $stderrFile);
        $this->assertSame('a', $stderrMode);
    }

    public function testBothRedirects(): void
    {
        [, $args, $stdoutFile, $stderrFile] = Parser::parse('cmd arg > out.txt 2> err.txt');
        $this->assertSame(['arg'], $args);
        $this->assertSame('out.txt', $stdoutFile);
        $this->assertSame('err.txt', $stderrFile);
    }

    public function testRedirectTokensRemovedFromArgs(): void
    {
        [, $args] = Parser::parse('echo hello > out.txt world');
        $this->assertSame(['hello', 'world'], $args);
    }

    // ---------------------------------------------------------------
    // splitPipeline()
    // ---------------------------------------------------------------

    public function testNoPipe(): void
    {
        $this->assertSame(['echo hello'], Parser::splitPipeline('echo hello'));
    }

    public function testSimplePipe(): void
    {
        $this->assertSame(['echo hello', 'wc'], Parser::splitPipeline('echo hello | wc'));
    }

    public function testThreeStagePipeline(): void
    {
        $segments = Parser::splitPipeline('cat file | head -n 3 | wc');
        $this->assertCount(3, $segments);
        $this->assertSame('cat file', $segments[0]);
        $this->assertSame('head -n 3', $segments[1]);
        $this->assertSame('wc', $segments[2]);
    }

    public function testPipeInsideSingleQuotesNotSplit(): void
    {
        $segments = Parser::splitPipeline("echo 'a|b'");
        $this->assertCount(1, $segments);
        $this->assertSame("echo 'a|b'", $segments[0]);
    }

    public function testPipeInsideDoubleQuotesNotSplit(): void
    {
        $segments = Parser::splitPipeline('echo "a|b"');
        $this->assertCount(1, $segments);
    }

    public function testPipeAfterBackslashNotSplit(): void
    {
        $segments = Parser::splitPipeline('echo a\\|b');
        $this->assertCount(1, $segments);
    }

    public function testSegmentsTrimmed(): void
    {
        $segments = Parser::splitPipeline('  echo hi  |  wc  ');
        $this->assertSame('echo hi', $segments[0]);
        $this->assertSame('wc', $segments[1]);
    }
}
