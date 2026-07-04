#!/bin/bash
# cleanup-old-plugin.sh
# Removes the old microvm.manager plugin completely from Unraid
# Run on Unraid BEFORE installing microvm.liquidmetal

echo "=== Cleaning up old microvm.manager plugin ==="

# 1. Stop all running microVMs
echo "Stopping VMs..."
for sock in /tmp/microvm-*.sock; do
  [ -S "$sock" ] || continue
  name=$(basename "$sock" .sock | sed 's/microvm-//')
  echo "  Stopping: $name"
  ch-remote --api-socket "$sock" shutdown-vmm 2>/dev/null
  pid=$(pgrep -f "microvm-${name}" 2>/dev/null | head -1)
  [ -n "$pid" ] && kill -9 "$pid" 2>/dev/null
done
sleep 2

# 2. Kill any ttyd processes for microVMs
pkill -f "ttyd.*microvm" 2>/dev/null
pkill -f "microvm-console" 2>/dev/null

# 3. Remove plugin from emhttp
echo "Removing plugin pages..."
rm -rf /usr/local/emhttp/plugins/microvm.manager

# 4. Remove rc.d script
echo "Removing rc script..."
rm -f /etc/rc.d/rc.microvm

# 5. Remove binaries (will be re-installed by new plugin)
echo "Removing binaries..."
rm -f /usr/local/bin/cloud-hypervisor
rm -f /usr/local/bin/ch-remote
rm -f /usr/local/bin/firecracker
rm -f /usr/local/bin/crane
rm -f /usr/local/bin/ttyd
rm -f /usr/local/bin/microvm-console

# 6. Remove TAP interfaces
echo "Removing TAP interfaces..."
for tap in $(ip -o link show | grep -oP 'tap-\S+(?=:)'); do
  ip link del "$tap" 2>/dev/null
  echo "  Removed: $tap"
done

# 7. Remove runtime sockets/pids
rm -f /tmp/microvm-*.sock
rm -f /var/tmp/microvm-*.sock
rm -f /var/tmp/ttyd-microvm-*.pid

# 8. Remove old VM data (WARNING: destroys all VMs!)
echo "Removing VM data at /mnt/user/microvms/..."
rm -rf /mnt/user/microvms

# 9. Remove old log files
rm -f /var/log/microvm-*.log

# 10. Remove old flash cache (keep binaries for re-use if needed)
echo "Removing old flash config..."
rm -f /boot/config/plugins/microvm.manager/microvm.manager.cfg
rm -f /boot/config/plugins/microvm.manager/*.tgz
# Keep binary files — they'll be reused by new plugin

echo ""
echo "=== Cleanup complete ==="
echo "Old microvm.manager removed."
echo "Binary cache kept at /boot/config/plugins/microvm.manager/ (rename manually if needed)"
echo ""
echo "Next: Install microvm.liquidmetal"
