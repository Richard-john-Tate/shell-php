# Stage 1: install dependencies
FROM composer:2 AS deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --optimize-autoloader

# Stage 2: runtime image
FROM php:8.5-cli

# Non-root user to run the shell — cannot modify app source
RUN groupadd --gid 1001 shelluser \
 && useradd  --uid 1001 --gid shelluser \
             --create-home --home-dir /home/shelluser \
             --shell /bin/bash shelluser

# Install app code as root (shelluser has read but not write access)
WORKDIR /opt/shell
COPY composer.json composer.lock ./
COPY --from=deps /app/vendor ./vendor/
COPY app/ app/
COPY tests/ tests/
COPY phpunit.xml* ./

RUN chown -R root:root /opt/shell \
 && chmod -R 755 /opt/shell

# Writable home directory for history file, temp files, etc.
RUN chown shelluser:shelluser /home/shelluser \
 && chmod 700 /home/shelluser

USER shelluser
WORKDIR /home/shelluser

ENV HOME=/home/shelluser
ENV USER=shelluser
# Set HISTFILE at runtime to enable persistent history, e.g.:
#   docker run -e HISTFILE=/home/shelluser/.shell_history ...
# Recommended runtime flags:
#   --read-only --tmpfs /tmp --cap-drop ALL --no-new-privileges -m 128m
CMD ["php", "/opt/shell/app/main.php"]
