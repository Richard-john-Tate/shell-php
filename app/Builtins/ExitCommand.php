<?php

declare(strict_types=1);

namespace App\Builtins;

/** Builtin `exit` — terminates the shell with an optional exit code. */
class ExitCommand implements BuiltinInterface
{
    public function execute(array $args, $stdout = null, $stderr = null): int
    {
        $code = isset($args[0]) ? (int)$args[0] : 0;
        exit($code);
    }
}
