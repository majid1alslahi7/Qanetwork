#!/bin/sh
# Run from hPanel once per minute. Pass the hosting PHP binary as argument 1.
set -u
umask 077
app_directory=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd) || exit 1
cd "$app_directory" || exit 1
php_binary=${1:-php}
result=0
"$php_binary" artisan schedule:run --no-interaction || result=1
worker_pids=''
for queue in sales reconciliation network-health delivery; do
    "$php_binary" artisan qanetwork:work "$queue" --no-interaction &
    worker_pids="$worker_pids $!"
done
for worker_pid in $worker_pids; do
    wait "$worker_pid" || result=1
done
exit "$result"
