#!/bin/sh
set -e

for dir in storage storage/cache storage/logs storage/sessions storage/tmp content/uploads; do
    target="/var/www/html/$dir"
    mkdir -p "$target"
    if ! su -s /bin/sh www-data -c "test -w '$target'" 2>/dev/null; then
        chmod 0777 "$target" 2>/dev/null || true
    fi
done

exec apache2-foreground
