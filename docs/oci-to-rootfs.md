# OCI Image to MicroVM Rootfs

## Tools

| Tool | Binary Size | Needs Daemon? | Best For |
|------|-------------|:---:|---------|
| `crane` | ~30MB static | ❌ | Pull + export OCI images (recommended) |
| `podman` | Large | ❌ | Full container workflow |
| `skopeo` + `umoci` | Medium | ❌ | OCI layout manipulation |

## Method 1: crane (Recommended for Unraid)

```bash
# Pull and export image filesystem as tar
crane export nginx:alpine /tmp/nginx-fs.tar

# Create ext4 rootfs image
dd if=/dev/zero of=/path/to/rootfs.raw bs=1M count=500
mkfs.ext4 -F /path/to/rootfs.raw

# Mount and extract
mkdir -p /tmp/rootfs
mount /path/to/rootfs.raw /tmp/rootfs
tar -xf /tmp/nginx-fs.tar -C /tmp/rootfs

# Inject init script (see below)
# ...

umount /tmp/rootfs
rm /tmp/nginx-fs.tar
```

## Method 2: podman export (tested on AlmaLinux)

```bash
podman pull docker.io/library/nginx:alpine
podman create --name tmp-export nginx:alpine
podman export tmp-export -o /tmp/nginx-fs.tar
podman rm tmp-export
# Then same ext4 creation + extraction as above
```

## Init Script Template

Every rootfs needs `/init` (or a proper init system). Minimal working init:

```sh
#!/bin/sh
mount -t proc proc /proc
mount -t sysfs sysfs /sys
mount -t devtmpfs devtmpfs /dev 2>/dev/null
mkdir -p /dev/pts && mount -t devpts devpts /dev/pts

# Auto-detect network interface
for d in /sys/class/net/*; do
  name=$(basename $d)
  [ "$name" != "lo" ] && IFACE=$name && break
done

# Network already configured by kernel ip= parameter
# Just ensure interface is up
ip link set $IFACE up 2>/dev/null
ip link set lo up

# Create runtime dirs for nginx
mkdir -p /run/nginx /var/log/nginx /var/lib/nginx/tmp
echo "nameserver 8.8.8.8" > /etc/resolv.conf

# Start the application
nginx -g 'daemon off;' &
APP_PID=$!

# Handle ACPI power button (graceful shutdown)
trap 'kill $APP_PID; sync; poweroff -f' TERM INT
while true; do sleep 3600 & wait; done
```

## Complete Automated Script

```bash
#!/bin/bash
# create-vm-from-oci.sh <image> <vm-name>
IMAGE="${1:-nginx:alpine}"
VM_NAME="${2:-web}"
VMDIR="/mnt/user/appdata/microvm/$VM_NAME"
KERNEL="/mnt/user/appdata/microvm/kernels/vmlinux"

mkdir -p "$VMDIR"

# Export OCI image
crane export "$IMAGE" /tmp/${VM_NAME}-fs.tar

# Create 500MB rootfs
dd if=/dev/zero of="$VMDIR/rootfs.raw" bs=1M count=500
mkfs.ext4 -F "$VMDIR/rootfs.raw"
mkdir -p /tmp/vm-mount
mount "$VMDIR/rootfs.raw" /tmp/vm-mount
tar -xf /tmp/${VM_NAME}-fs.tar -C /tmp/vm-mount

# Inject init script
cat > /tmp/vm-mount/init << 'EOF'
#!/bin/sh
mount -t proc proc /proc
mount -t sysfs sysfs /sys
mount -t devtmpfs devtmpfs /dev 2>/dev/null
for d in /sys/class/net/*; do n=$(basename $d); [ "$n" != "lo" ] && IFACE=$n && break; done
ip link set $IFACE up 2>/dev/null
ip link set lo up
mkdir -p /run/nginx /var/log/nginx /var/lib/nginx/tmp
echo "nameserver 8.8.8.8" > /etc/resolv.conf
nginx -g 'daemon off;' &
trap 'kill $!; poweroff -f' TERM INT
while true; do sleep 3600 & wait; done
EOF
chmod +x /tmp/vm-mount/init

umount /tmp/vm-mount
rm -f /tmp/${VM_NAME}-fs.tar

# Create VM config
cat > "$VMDIR/config.json" << CONF
{
  "name": "$VM_NAME",
  "kernel": "$KERNEL",
  "disk": "$VMDIR/rootfs.raw",
  "cmdline": "console=hvc0 root=/dev/vda rw init=/init ip=192.168.50.201::192.168.50.1:255.255.255.0:::off",
  "boot_vcpus": 1,
  "max_vcpus": 2,
  "memory_mb": 256,
  "mac": "52:54:00:$(printf '%02x:%02x:%02x' $((RANDOM%256)) $((RANDOM%256)) $((RANDOM%256)))",
  "autostart": true
}
CONF

echo "VM '$VM_NAME' created from $IMAGE at $VMDIR"
echo "Start with: /etc/rc.d/rc.microvm start_vm $VM_NAME"
```
