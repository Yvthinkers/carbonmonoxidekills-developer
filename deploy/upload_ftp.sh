#!/usr/bin/env bash
# Upload v3/live/ to the cPanel docroot over FTP (overlay: adds files, deletes nothing).
# Usage: FTP_HOST=107.180.118.108 FTP_USER='user@domain' FTP_PASS='...' ./deploy/upload_ftp.sh
set -euo pipefail
: "${FTP_HOST:?}" "${FTP_USER:?}" "${FTP_PASS:?}"
cd "$(dirname "$0")/../v3/live"
n=0
for f in $(find . -type f | sed 's|^\./||' | sort); do
  curl -s --fail --ssl -k --ftp-pasv --ftp-create-dirs -u "$FTP_USER:$FTP_PASS" -T "$f" "ftp://$FTP_HOST/$f"
  n=$((n+1))
done
echo "uploaded $n files"
