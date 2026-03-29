<?php
namespace App\Builtins;

use App\Executor;

class TypeCommand implements BuiltinInterface
{
    private array $builtins;

    public function __construct(array $builtins)
    {
        $this->builtins = $builtins;
    }

    public function execute(array $args, $stdout = null, $stderr = null): void
    {
        $out = $stdout ?? STDOUT;
        $command = $args[0] ?? null;
        if ($command === null) return;

        if (isset($this->builtins[$command])) {
            fwrite($out, "$command is a shell builtin\n");
            return;
        }

        $path = Executor::findInPath($command);
        if ($path !== null) {
            fwrite($out, "$command is $path\n");
            return;
        }

        fwrite($out, "$command: not found\n");
    }
}