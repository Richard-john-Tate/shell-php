<?php
namespace Tests\Integration;

class PipelineTest extends ShellTestCase
{
    public function testTwoCommandPipeline(): void
    {
        $out = trim($this->outputOf($this->runCommand('echo hello | wc -w')));
        $this->assertSame('1', $out);
    }

    public function testThreeCommandPipeline(): void
    {
        // "one\ntwo\nthree" piped through head -n 2 then wc -l → "2"
        $out = trim($this->outputOf(
            $this->runCommand('printf "one\ntwo\nthree\n" | head -n 2 | wc -l')
        ));
        $this->assertSame('2', $out);
    }

    public function testBuiltinAsSource(): void
    {
        $out = trim($this->outputOf($this->runCommand('echo hello world | wc -w')));
        $this->assertSame('2', $out);
    }

    public function testBuiltinAsSink(): void
    {
        // ls output piped into type — type only cares about its arguments, not stdin
        $out = trim($this->outputOf($this->runCommand('echo ignored | type echo')));
        $this->assertSame('echo is a shell builtin', $out);
    }
}
