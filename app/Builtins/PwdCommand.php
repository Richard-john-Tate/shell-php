<?php
namespace App\Builtins;

class PwdCommand implements BuiltinInterface
{
    public function execute(array $args, $stdout = null, $stderr = null): void
    {
        fwrite($stdout ?? STDOUT, getcwd() . "\n");
    }
}