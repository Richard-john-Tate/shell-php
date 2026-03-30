<?php

declare(strict_types=1);

namespace App\Builtins;

/** Builtin `pwd` — prints the current working directory. */
class PwdCommand implements BuiltinInterface
{
    public function execute(array $args, $stdout = null, $stderr = null): int
    {
        fwrite($stdout ?? STDOUT, getcwd() . "\n");
        return 0;
    }
}
