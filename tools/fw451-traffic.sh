#!/bin/sh
# Lane FW: bot traffic against the fw451 preview, so the live view has
# something real to show. Addresses go in X-Fw-Preview-Ip (tools/fw451-router.php).
#   sh tools/fw451-traffic.sh http://127.0.0.1:9934
B=${1:-http://127.0.0.1:9934}
P=/product/advanced-snail-96-mucin-power-essence/
hit() { curl -s -o /dev/null -w "%{http_code}\n" -H "X-Fw-Preview-Ip: $1" -H 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0 Safari/537.36' $3 "$B$2"; }
# 1. one Chinese address hammering a product page, then the cart
for i in $(seq 1 45); do hit 36.110.5.5 $P; done | sort | uniq -c
hit 36.110.5.5 /cart/
# 2. twenty fresh Chinese and Hong Kong addresses posting add-to-cart without loading a page
for i in $(seq 1 12); do hit 36.110.7.$i /api/cart/add "-X POST -H Accept:application/json"; done | sort | uniq -c
for i in $(seq 1 8); do hit 1.36.10.$i /api/cart/add "-X POST -H Accept:application/json"; done | sort | uniq -c
# 3. a Russian /24 rotating addresses: 50 addresses x 10 requests
for a in $(seq 1 50); do for i in $(seq 1 10); do hit 95.24.1.$a $P; done; done | sort | uniq -c
hit 95.24.1.77 /cart/
# 4. a Singapore datacenter scraping steadily
for i in $(seq 1 40); do hit 103.253.144.9 /shop/; done | sort | uniq -c
