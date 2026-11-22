#!/usr/bin/env bash
# Press "Request indexing" for the next URLs in tools/indexing_queue.txt (Google allows ~10-12 a day).
# Needs the logged-in Chrome window used by tools/request_indexing.py. Run once a day:
#     tools/daily_indexing.sh
# URLs that Google accepted are removed from the queue; the rest stay for the next run.
cd "$(dirname "$0")/.." || exit 1
LOG="$HOME/cmk-rebrand-backups/indexing/log.txt"
mkdir -p "$(dirname "$LOG")"
mapfile -t urls < <(grep -v '^\s*$' tools/indexing_queue.txt | head -12)
if [ ${#urls[@]} -eq 0 ]; then echo "$(date -Is) queue empty" | tee -a "$LOG"; exit 0; fi
if ! curl -s -m 5 http://127.0.0.1:9222/json/version >/dev/null; then
  echo "$(date -Is) Chrome window not open: start it and log in (see tools/request_indexing.py)" | tee -a "$LOG"; exit 1
fi
out=$(PYTHONUNBUFFERED=1 timeout 3000 python3 tools/request_indexing.py "${urls[@]}" 2>&1)
echo "$out" | grep -E '\||quota|Error|Traceback' | sed "s/^/$(date -I) /" | tee -a "$LOG"
accepted=$(echo "$out" | grep 'Indexing requested' | cut -d' ' -f1)
if [ -n "$accepted" ]; then
  grep -vxF -f <(echo "$accepted") tools/indexing_queue.txt > tools/indexing_queue.tmp
  mv tools/indexing_queue.tmp tools/indexing_queue.txt
fi
echo "$(grep -c . tools/indexing_queue.txt) URLs left in the queue"
