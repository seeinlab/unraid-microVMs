#!/bin/bash
echo "=== Docker containerd ==="
ps aux | grep containerd | grep -v grep | head -3

echo ""
echo "=== Docker storage driver ==="
docker info 2>/dev/null | grep -i "storage driver"
docker info 2>/dev/null | grep -i "docker root"

echo ""
echo "=== Current microVM paths ==="
ls -d /mnt/user/microvms/*/ 2>/dev/null

echo ""
echo "=== /dev/mapper devices ==="
ls /dev/mapper/ 2>/dev/null

echo ""
echo "=== Loopback devices ==="
losetup -a 2>/dev/null

echo ""
echo "=== dm-thin-pool module ==="
modprobe --dry-run dm_thin_pool 2>&1
lsmod | grep dm

echo ""
echo "=== Unraid flash plugin dir ==="
ls /boot/config/plugins/microvm.manager/ 2>/dev/null

echo ""
echo "=== Free space on cache ==="
df -h /mnt/cache 2>/dev/null | tail -1

echo ""
echo "=== containerd already running? ==="
which containerd 2>/dev/null
ls /run/containerd/containerd.sock 2>/dev/null
