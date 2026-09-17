#!/bin/sh
ps -eo pid,args | grep '[p]hp -S 127' | awk '{print $1}' | xargs -r kill 2>/dev/null
sleep 1
export PHP_CLI_SERVER_WORKERS=6 SESSION_DRIVER=file KBB_PUBLIC_PATH=/home/user/kbbstore/public-web-root
nohup php -S 127.0.0.1:8903 -t public-web-root /tmp/claude-0/-home-user-kbbstore/719ff49f-5d63-5981-9f03-fffb8cde43b0/scratchpad/int-router.php > /tmp/claude-0/-home-user-kbbstore/719ff49f-5d63-5981-9f03-fffb8cde43b0/scratchpad/preview.log 2>&1 &
