<?php
namespace App\Builtins;

interface BuiltinInterface
{
    public function execute(array $args, $stdout = null, $stderr = null): int;
}
