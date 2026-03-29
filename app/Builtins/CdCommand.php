<?php
namespace App\Builtins;

class CdCommand implements BuiltinInterface
{
 public function execute(array $args, $stdout = null, $stderr = null): void
    {
        $dir = $args[0] ?? null;

        if ($dir === null) return;

        // Replace ~ with HOME directory
        if ($dir === '~') {
            $dir = getenv('HOME');
        }

        if (!is_dir($dir)) {
            fwrite($stderr ?? STDERR, "cd: $dir: No such file or directory\n");
            return;
        }

        chdir($dir);
    }
}