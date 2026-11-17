#!/bin/sh
set -e

# The two cache directories are created lazily by whichever process needs them first — `bin/prime`
# runs as root in this image while Apache runs as www-data, so a 0750 directory left by the CLI
# would lock the web process out of its own cache for good. Create them up front instead.
for dir in storage storage/cache storage/cache/pages storage/cache/data storage/logs storage/sessions storage/tmp content/uploads; do
    target="/var/www/html/$dir"
    mkdir -p "$target"
    if ! su -s /bin/sh www-data -c "test -w '$target'" 2>/dev/null; then
        chmod 0777 "$target" 2>/dev/null || true
    fi
done

exec apache2-foreground
