#!/bin/bash
echo "=== Test Results $(date) ==="

echo ""
echo "## 1. VM Status"
echo "CH ping: $(ch-remote --api-socket /tmp/microvm-nginx-ch.sock ping 2>&1 | grep -o 'v[0-9.]*')"
echo "CH http: $(curl -s --connect-timeout 2 http://192.168.50.220/ | head -1)"
echo "FC http: $(curl -s --connect-timeout 2 http://192.168.50.221/ | head -1)"

echo ""
echo "## 2. Resize (already done earlier)"
echo "CH cpus: $(ch-remote --api-socket /tmp/microvm-nginx-ch.sock info 2>/dev/null | grep -o 'boot_vcpus.:[0-9]*')"

echo ""
echo "## 3. Snapshots"
echo "CH snapshots: $(ls /mnt/user/microvms/nginx-ch/snapshots/ 2>/dev/null)"
echo "FC snapshots: $(ls /mnt/user/microvms/nginx-fc/snapshots/ 2>/dev/null)"

echo ""
echo "## 4. Console PTY"
PTY=$(grep -oP 'file: Some\("\K/dev/pts/\d+' /mnt/user/microvms/nginx-ch/vm.log 2>/dev/null | tail -1)
echo "CH PTY: ${PTY:-NOT FOUND}"
echo "PTY exists: $(test -e "$PTY" && echo YES || echo NO)"

echo ""
echo "## 5. Log files"
ls -la /mnt/user/microvms/nginx-ch/vm.log /mnt/user/microvms/nginx-fc/vm.log 2>&1

echo ""
echo "## 6. FC API"
echo "FC info: $(curl -s --unix-socket /tmp/microvm-nginx-fc.sock http://localhost/)"

echo ""
echo "## 7. Config files"
echo "CH autostart: $(grep autostart /mnt/user/microvms/nginx-ch/config.json)"
echo "FC autostart: $(grep autostart /mnt/user/microvms/nginx-fc/config.json)"

echo ""
echo "## 8. Context menu items verification"
echo "CH engine in config: $(grep engine /mnt/user/microvms/nginx-ch/config.json)"
echo "FC engine in config: $(grep engine /mnt/user/microvms/nginx-fc/config.json)"

echo ""
echo "=== ALL TESTS COMPLETE ==="
