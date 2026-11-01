#!/usr/bin/env bash
# Deploy the dev preview (v3/dist + deploy/dev.carbonmonoxidekills.com/.htaccess) to the EC2 dev server.
# Usage: KEY=~/.ssh/cmk-dev-site.pem HOST=184.197.42.49 ./deploy/deploy_ec2_dev.sh
set -euo pipefail
: "${KEY:?}" "${HOST:?}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
cp -a "$ROOT/v3/dist/." "$TMP/"
cp "$ROOT/deploy/dev.carbonmonoxidekills.com/.htaccess" "$ROOT/ec2/site/robots.txt" "$TMP/"
(cd "$TMP" && zip -qr "$TMP.zip" .)
O="-i $KEY -o IdentitiesOnly=yes"
scp $O "$TMP.zip" "ec2-user@$HOST:/tmp/up.zip"
ssh $O "ec2-user@$HOST" 'set -e; sudo tar czf ~/docroot-backup-$(date +%F).tgz -C /var/www/html .; rm -rf /tmp/new && mkdir /tmp/new && unzip -q /tmp/up.zip -d /tmp/new; sudo find /var/www/html -mindepth 1 -delete; sudo cp -a /tmp/new/. /var/www/html/; sudo chmod -R a+rX /var/www/html; sudo apachectl configtest'
