<?php
namespace App\Builtins;

class ExitCommand implements BuiltinInterface
{
    public function execute(array $args, $stdout = null, $stderr = null): void
    {
        $code = isset($args[0]) ? (int)$args[0] : 0;
        exit($code);
    }
}