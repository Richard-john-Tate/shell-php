# shell-php

A POSIX-compliant shell interpreter built in PHP 8.5.

## Features

- Interactive REPL with `readline` (history, tab completion, arrow navigation)
- Builtins: `echo`, `exit`, `type`, `pwd`, `cd`, `history`
- Tab completion: builtins, PATH executables, filenames, nested paths, directories
- Pipelines: multi-stage, mixed builtin/external
- I/O redirection: `>`, `>>`, `2>`, `2>>` (stdout/stderr, truncate/append)
- History: in-memory, `history -r/-w/-a`, `HISTFILE` load on startup / save on exit

## Run with Docker

```bash
docker build -t shell-php .
docker run -it shell-php
```

## Run locally

```bash
php app/main.php
```

Requires PHP 8.5+ with the `readline` extension.

## Run Tests

Tests run inside Docker to ensure a consistent Unix environment:

```bash
docker build -t codecrafters-shell-php .
docker run --rm -v "$(pwd):/app" codecrafters-shell-php php vendor/bin/phpunit
```

Run specific test suites:

```bash
# Unit tests only
docker run --rm -v "$(pwd):/app" codecrafters-shell-php php vendor/bin/phpunit --testsuite Unit

# Integration tests only
docker run --rm -v "$(pwd):/app" codecrafters-shell-php php vendor/bin/phpunit --testsuite Integration
```
