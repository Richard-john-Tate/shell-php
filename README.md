# shell-php

![PHP 8.5](https://img.shields.io/badge/PHP-8.5-8892BF?logo=php&logoColor=white)
![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)

A POSIX-ish shell interpreter built in PHP 8.5, originally developed as a [CodeCrafters](https://codecrafters.io) challenge and extended into a portfolio showcase.

<!-- Uncomment once you have a demo recording:
![shell-php demo](docs/demo.gif)
-->

---

## Features

### Builtins
| Command | Behaviour |
|---------|-----------|
| `echo [-n] [-e] [args...]` | Print arguments; `-n` suppresses newline, `-e` interprets `\n \t \\` etc. |
| `cd [dir]` | Change directory; bare `cd` → `$HOME`, `cd -` → previous dir; updates `$PWD`/`$OLDPWD` |
| `pwd` | Print working directory |
| `type [cmd...]` | Identify each argument as a builtin, executable path, or not found |
| `history [-r/-w/-a file] [n]` | In-memory history; `-r` read, `-w` write, `-a` append-new-only; `HISTFILE` loaded on startup |
| `exit [code]` | Exit with optional status code |

### Shell features
- **Dynamic prompt** — `user@hostname:~/cwd$ ` — updates after every `cd`
- **Variable expansion** — `$VAR`, `${VAR}`, `$?` (last exit code), `$$` (PID) — works in plain context and double quotes, never inside single quotes
- **Exit code tracking** — `$?` always reflects the last command's exit code
- **Pipelines** — multi-stage (`cmd1 | cmd2 | cmd3`), mixed builtin/external; all-external pipelines run concurrently via OS pipes
- **I/O redirection** — `>`, `>>`, `2>`, `2>>` (stdout/stderr, truncate/append), `<` (stdin); no-space syntax `echo hello>file` works correctly
- **Quote handling** — single quotes (fully literal), double quotes (expansion + `\\`/`\"` escaping), backslash outside quotes
- **Tab completion** — builtins, PATH executables, filenames with directory-awareness
- **History** — readline-backed with `history -r/-w/-a` and `HISTFILE` load/append-on-exit

## Run with Docker

```bash
docker build -t shell-php .
docker run -it --rm shell-php
```

With persistent history:
```bash
docker run -it --rm \
  -e HISTFILE=/home/shelluser/.shell_history \
  shell-php
```

Hardened (recommended for public demos):
```bash
docker run -it --rm \
  --cap-drop ALL \
  --no-new-privileges \
  --read-only \
  --tmpfs /tmp \
  -m 128m \
  shell-php
```

## Run locally

Requires PHP 8.5+ with the `readline` extension and [Composer](https://getcomposer.org).

```bash
composer install
php app/main.php
```

## Run tests

Tests are designed to run inside the container (Linux paths, Unix tools). On Linux/macOS you can also run them locally after `composer install`.

```bash
docker build -t shell-php .
docker run --rm -w /opt/shell --entrypoint php shell-php /opt/shell/vendor/bin/phpunit --testdox
```

Run a specific suite:
```bash
# Unit tests only (parser)
docker run --rm -w /opt/shell --entrypoint php shell-php \
  /opt/shell/vendor/bin/phpunit --testdox --testsuite Unit

# Integration tests only
docker run --rm -w /opt/shell --entrypoint php shell-php \
  /opt/shell/vendor/bin/phpunit --testdox --testsuite Integration
```

## Security model

The Docker image runs the shell as an unprivileged `shelluser` (uid 1001). Application source lives at `/opt/shell`, owned by root — `shelluser` can read but not modify it. The working directory is `/home/shelluser` (writable). Combine with `--cap-drop ALL --no-new-privileges --read-only` at runtime for a fully locked-down demo environment.

## Architecture

```
app/
├── main.php              Entry point
├── Shell.php             REPL, prompt, tab completion, pipeline orchestration
├── Parser.php            Tokeniser + redirection/pipeline extraction + variable expansion
├── Executor.php          External command execution (single + pipeline)
└── Builtins/
    ├── BuiltinInterface.php
    ├── EchoCommand.php
    ├── CdCommand.php
    ├── PwdCommand.php
    ├── TypeCommand.php
    ├── HistoryCommand.php
    └── ExitCommand.php
```

Pipeline execution uses two strategies: all-external pipelines are wired via OS pipes for true concurrency (supports `tail -f`); any pipeline containing a builtin runs sequentially with `php://temp` buffers between stages.

## License

This project is released under the [MIT License](LICENSE).
