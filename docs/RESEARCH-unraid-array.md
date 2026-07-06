# Unraid Array Integration — Research Notes

## Date: 2026-07-06

## Problem
PHP `exec()` from Unraid's emhttpd context fails to write files into loop-mounted filesystems, even though the mount and tar extraction work.

## How Unraid's Built-in Plugins Handle This

### VM Manager (dynamix.vm.manager)
- **NEVER** mounts disk images or injects files from PHP
- Creates disk images with `qemu-img create` (just makes the file)
- OS installation handled by guest (boots from ISO)
- All VM operations delegated to **libvirt** (external daemon)
- PHP only generates XML config and passes to libvirt API

### Docker Plugin (dynamix.docker.manager)
- **NEVER** mounts container filesystems from PHP
- Communicates with Docker daemon via **REST API** (Unix socket)
- Templates stored on flash (`/boot/config/plugins/dockerMan/`)
- All container/image operations delegated to Docker daemon
- PHP is just the UI layer

### Pattern: Delegate to External Process
Both plugins follow the same pattern:
1. PHP generates config (XML for VMs, template for Docker)
2. Heavy operations delegated to daemon/script (libvirt, dockerd)
3. PHP never does direct filesystem operations on mounted devices

## Root Cause of Our Bug

Each `exec()` call in PHP spawns a **new shell process**. On Unraid:
- `exec("mount $dev /tmp/mountpoint")` — mounts in the child process's namespace
- The mount MAY be visible to subsequent `exec()` calls (shared mount namespace)
- BUT: individual `exec()` calls may have different working contexts
- The `symlink()` PHP function works because it creates a file in the parent dir (no mount needed)
- `mkdir` and `cp` into the mount point fail because they can't see the mount

## Fix: Single Script for All rootfs Operations

Bundle ALL operations (mount → extract → inject → unmount) into ONE shell script execution:

```php
// Instead of multiple exec() calls:
$script = <<<BASH
#!/bin/bash
set -e
ROOTFS="$rootfs"
MOUNT="/tmp/microvm-mount-$name"
MOUNTDEV="$mountDev"

# Mount
mount \$MOUNTDEV \$MOUNT

# Extract OCI tar
tar -xf $tmpTar -C \$MOUNT 2>&1

# Inject init files
mkdir -p \$MOUNT/fly \$MOUNT/sbin
cp /usr/local/share/microvms/fly-init \$MOUNT/fly/init
chmod 755 \$MOUNT/fly/init
cp /usr/local/share/microvms/catatonit \$MOUNT/sbin/catatonit
chmod 755 \$MOUNT/sbin/catatonit
ln -sf /fly/init \$MOUNT/init

# Write run.json
cat > \$MOUNT/fly/run.json << 'EOF'
$runJson
EOF

# Unmount
umount \$MOUNT
rmdir \$MOUNT
BASH;

// Write script and execute as one unit
$scriptPath = "/tmp/microvm-create-$name.sh";
file_put_contents($scriptPath, $script);
chmod($scriptPath, 0755);
exec($scriptPath . " 2>&1", $output, $ret);
unlink($scriptPath);
```

This ensures mount → operations → unmount all happen in the **same process context**.

## Unraid Array Path Notes

- `/mnt/user/` = FUSE filesystem (shfs) that aggregates all disks + cache
- `/mnt/user/` is NOT available at boot (array must be online)
- `/mnt/cache/`, `/mnt/disk1/`, `/mnt/ztier/` = actual device mounts
- Loop mounting from `/mnt/user/` works but the FUSE layer adds complexity
- Better to resolve to actual device path before mounting (our existing code does this)
- PHP `file_exists()` on `/mnt/user/` works (FUSE handles it)
- But `exec("mount -o loop /mnt/user/.../file /mountpoint")` may behave differently in child processes

## Key Takeaway

**Never do multi-step filesystem operations with separate `exec()` calls on Unraid.**
Always bundle them into a single script execution to guarantee mount namespace consistency.
