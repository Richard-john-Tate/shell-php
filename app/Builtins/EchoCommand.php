<?php
namespace App\Builtins;

class EchoCommand implements BuiltinInterface
{
    public function execute(array $args, $stdout = null, $stderr = null): void
    {
        fwrite($stdout ?? STDOUT, implode(' ', $args) . "\n");
    }
}