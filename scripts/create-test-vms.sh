#!/bin/bash
# Create fresh nginx-ch and nginx-fc VMs on Unraid
VMDIR="/mnt/user/microvms"

# Clean old TAPs
ip link del tap-nginx2 2>/dev/null
ip link del tap-nginx-test 2>/dev/null
ip link del tap-fc-nginx 2>/dev/null

# Create nginx-ch (Cloud Hypervisor)
echo "=== Creating nginx-ch (Cloud Hypervisor) ==="
mkdir -p $VMDIR/nginx-ch
cat > $VMDIR/nginx-ch/config.json << 'EOF'
{
  "name": "nginx-ch",
  "engine": "cloud-hypervisor",
  "kernel": "/mnt/user/microvms/kernels/vmlinux",
  "disk": "/mnt/user/microvms/nginx-ch/rootfs.raw",
  "cmdline": "console=ttyS0 root=/dev/vda rw init=/init ip=192.168.50.220::192.168.50.1:255.255.255.0:::off",
  "boot_vcpus": 1,
  "max_vcpus": 4,
  "memory_mb": 256,
  "mac": "52:54:00:a1:b2:c3",
  "ip": "192.168.50.220",
  "bridge": "br0",
  "autostart": false
}
EOF

# Create rootfs from nginx:alpine
echo "Pulling nginx:alpine..."
crane export nginx:alpine /tmp/nginx-ch.tar 2>&1
dd if=/dev/zero of=$VMDIR/nginx-ch/rootfs.raw bs=1M count=500 2>/dev/null
mkfs.ext4 -F $VMDIR/nginx-ch/rootfs.raw 2>/dev/null
mkdir -p /tmp/mnt-nginx-ch
mount $VMDIR/nginx-ch/rootfs.raw /tmp/mnt-nginx-ch
tar -xf /tmp/nginx-ch.tar -C /tmp/mnt-nginx-ch 2>/dev/null

# Inject init script with serial console support
cat > /tmp/mnt-nginx-ch/init << 'INITEOF'
#!/bin/sh
mount -t proc proc /proc
mount -t sysfs sysfs /sys
mount -t devtmpfs devtmpfs /dev 2>/dev/null
mkdir -p /dev/pts && mount -t devpts devpts /dev/pts
for d in /sys/class/net/*; do n=$(basename $d); [ "$n" != "lo" ] && IFACE=$n && break; done
ip link set $IFACE up 2>/dev/null
ip link set lo up
mkdir -p /run/nginx /var/log/nginx /var/lib/nginx/tmp /tmp
echo "nameserver 8.8.8.8" > /etc/resolv.conf
chown -R root:root /var/log/nginx /run/nginx 2>/dev/null
nginx -g 'daemon off;' 2>/tmp/nginx-err.log &
NGINX_PID=$!
sleep 2
if ! kill -0 $NGINX_PID 2>/dev/null; then
  echo "events{} http{server{listen 80;location / {root /usr/share/nginx/html;index index.html;}}}" > /tmp/nginx-min.conf
  nginx -c /tmp/nginx-min.conf -g 'daemon off;' &
fi
echo "=== MicroVM Ready ==="
if [ -c /dev/ttyS0 ]; then
  while true; do setsid sh -l < /dev/ttyS0 > /dev/ttyS0 2>&1; sleep 1; done &
fi
trap 'kill $(jobs -p) 2>/dev/null; poweroff -f' TERM INT
while true; do sleep 3600 & wait; done
INITEOF
chmod +x /tmp/mnt-nginx-ch/init
umount /tmp/mnt-nginx-ch
rmdir /tmp/mnt-nginx-ch
rm -f /tmp/nginx-ch.tar
echo "nginx-ch created OK"

# Create nginx-fc (Firecracker)
echo "=== Creating nginx-fc (Firecracker) ==="
mkdir -p $VMDIR/nginx-fc
cat > $VMDIR/nginx-fc/config.json << 'EOF'
{
  "name": "nginx-fc",
  "engine": "firecracker",
  "kernel": "/mnt/user/microvms/kernels/vmlinux",
  "disk": "/mnt/user/microvms/nginx-fc/rootfs.raw",
  "cmdline": "console=hvc0 root=/dev/vda rw init=/init ip=192.168.50.221::192.168.50.1:255.255.255.0:::off",
  "boot_vcpus": 1,
  "max_vcpus": 2,
  "memory_mb": 256,
  "mac": "52:54:00:d4:e5:f6",
  "ip": "192.168.50.221",
  "bridge": "br0",
  "autostart": false
}
EOF

# Reuse same rootfs for FC
echo "Creating FC rootfs..."
cp $VMDIR/nginx-ch/rootfs.raw $VMDIR/nginx-fc/rootfs.raw
echo "nginx-fc created OK"

echo "=== Starting VMs ==="
/etc/rc.d/rc.microvm start_vm nginx-ch
sleep 3
/etc/rc.d/rc.microvm start_vm nginx-fc
sleep 5

echo "=== Testing ==="
echo "nginx-ch (192.168.50.220):"
curl -s --connect-timeout 3 http://192.168.50.220/ | head -2
echo ""
echo "nginx-fc (192.168.50.221):"
curl -s --connect-timeout 3 http://192.168.50.221/ | head -2
echo ""
echo "=== DONE ==="
