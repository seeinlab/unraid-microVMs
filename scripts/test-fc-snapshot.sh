#!/bin/bash
# Test FC snapshot via API
SOCK=/tmp/microvm-nginx-fc.sock
SNAPDIR=/mnt/user/microvms/nginx-fc/snapshots/test-fc-api

echo "=== FC Status ==="
curl -s --unix-socket $SOCK http://localhost/

echo ""
echo "=== Pause ==="
HTTP=$(curl -s -o /dev/null -w "%{http_code}" --unix-socket $SOCK -X PATCH \
  -H "Content-Type: application/json" \
  -d '{"state":"Paused"}' http://localhost/vm)
echo "HTTP: $HTTP"

echo "=== Create Snapshot ==="
mkdir -p $SNAPDIR
HTTP=$(curl -s -o /dev/null -w "%{http_code}" --unix-socket $SOCK -X PUT \
  -H "Content-Type: application/json" \
  -d "{\"snapshot_type\":\"Full\",\"snapshot_path\":\"$SNAPDIR/snapshot\",\"mem_file_path\":\"$SNAPDIR/mem\"}" \
  http://localhost/snapshot/create)
echo "HTTP: $HTTP"

echo "=== Resume ==="
HTTP=$(curl -s -o /dev/null -w "%{http_code}" --unix-socket $SOCK -X PATCH \
  -H "Content-Type: application/json" \
  -d '{"state":"Resumed"}' http://localhost/vm)
echo "HTTP: $HTTP"

echo "=== Snapshot Files ==="
ls -la $SNAPDIR/

echo "=== Verify VM still running ==="
curl -s --unix-socket $SOCK http://localhost/
echo ""
curl -s --connect-timeout 3 http://192.168.50.221/ | head -1
