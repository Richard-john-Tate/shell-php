<?php
namespace Tests\Integration;

class BuiltinsTest extends ShellTestCase
{
    public function testEcho(): void
    {
        $out = $this->outputOf($this->runCommand('echo hello world'));
        $this->assertSame("hello world\n", $out);
    }

    public function testEchoSingleQuotes(): void
    {
        $out = $this->outputOf($this->runCommand("echo 'hello world'"));
        $this->assertSame("hello world\n", $out);
    }

    public function testEchoDoubleQuotes(): void
    {
        $out = $this->outputOf($this->runCommand('echo "hello world"'));
        $this->assertSame("hello world\n", $out);
    }

    public function testPwd(): void
    {
        $out = trim($this->outputOf($this->runCommand('pwd')));
        $this->assertNotEmpty($out);
        $this->assertDirectoryExists($out);
    }

    public function testCdAndPwd(): void
    {
        $tmp = sys_get_temp_dir();
        $this->runCommand("cd $tmp");
        $out = trim($this->outputOf($this->runCommand('pwd')));
        $this->assertSame(realpath($tmp), realpath($out));
    }

    public function testCdNonExistent(): void
    {
        $out = $this->outputOf($this->runCommand('cd /this/does/not/exist/xyz'));
        $this->assertStringContainsString('No such file or directory', $out);
    }

    public function testTypeBuiltin(): void
    {
        $out = trim($this->outputOf($this->runCommand('type echo')));
        $this->assertSame('echo is a shell builtin', $out);
    }

    public function testTypeExternal(): void
    {
        $out = trim($this->outputOf($this->runCommand('type cat')));
        $this->assertMatchesRegularExpression('/cat is \/.*cat/', $out);
    }

    public function testTypeNotFound(): void
    {
        $out = trim($this->outputOf($this->runCommand('type nonexistent_xyz_abc')));
        $this->assertStringContainsString('not found', $out);
    }

    public function testUnknownCommand(): void
    {
        $out = trim($this->outputOf($this->runCommand('foobar_unknown')));
        $this->assertStringContainsString('command not found', $out);
    }
}
