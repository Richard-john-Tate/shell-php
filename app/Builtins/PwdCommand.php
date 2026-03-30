<?php
namespace App\Builtins;

class PwdCommand implements BuiltinInterface
{
    public function execute(array $args, $stdout = null, $stderr = null): int
    {
        fwrite($stdout ?? STDOUT, getcwd() . "\n");
        return 0;
    }
}
