# PLAN: Init Refactor — /fly/run.json Pattern

## Goal
Replace per-VM generated shell `/init` with a **generic init binary + injected `/fly/run.json`** config. Same pattern as Fly.io's init-snapshot.

## Architecture

```
HOST SIDE (unchanged):
  /mnt/ztier/microvms/{name}/firecracker.json   ← VMM hardware config (cpus, mem, disk, net)
  /mnt/ztier/microvms/{name}/cloud-hypervisor.json

GUEST SIDE (new):
  /sbin/init        ← generic catatonit binary (same for ALL VMs)
  /fly/init         ← our shell init wrapper (same for ALL VMs, reads run.json)
  /fly/run.json     ← per-VM runtime config (injected at create time)
```

## /fly/run.json Schema

```json
{
  "entrypoint": ["/docker-entrypoint.sh"],
  "cmd": ["nginx", "-g", "daemon off;"],
  "env": {
    "PATH": "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
    "HOME": "/root",
    "TERM": "dumb"
  },
  "working_dir": "/",
  "user": "root",
  "hostname": "ch-nginx-1",
  "network": {
    "ip": "192.168.50.220",
    "gateway": "192.168.50.1",
    "mask": "255.255.255.0",
    "dns": ["8.8.8.8", "1.1.1.1"]
  },
  "console": true,
  "tty": true
}
```

## /fly/init (generic shell wrapper)

```sh
#!/bin/sh
# Generic microVM init — reads /fly/run.json for config
# This file is the SAME for every VM. Only /fly/run.json differs.

# 1. Mount virtual filesystems
mount -t proc proc /proc
mount -t sysfs sysfs /sys
mount -t devtmpfs devtmpfs /dev 2>/dev/null
mkdir -p /dev/pts && mount -t devpts devpts /dev/pts

# 2. Parse /fly/run.json (use busybox-compatible JSON parsing)
CONFIG="/fly/run.json"
if [ ! -f "$CONFIG" ]; then
  echo "FATAL: /fly/run.json not found"
  sleep 5
  exit 1
fi

# Extract fields (lightweight jq alternative for busybox)
# Option A: use a tiny JSON parser (included)
# Option B: parse with grep/sed for simple fields
# Option C: include jq static binary (~1MB)

# 3. Set hostname
HOSTNAME=$(json_get hostname)
[ -n "$HOSTNAME" ] && hostname "$HOSTNAME"

# 4. Configure network
IP=$(json_get network.ip)
GW=$(json_get network.gateway)
DNS=$(json_get network.dns[])
IFACE=$(ls /sys/class/net/ | grep -v lo | head -1)
if [ -n "$IP" ]; then
  ip link set lo up
  ip link set $IFACE up
  ip addr add ${IP}/24 dev $IFACE
  [ -n "$GW" ] && ip route add default via $GW dev $IFACE
fi
for ns in $DNS; do
  echo "nameserver $ns" >> /etc/resolv.conf
done

# 5. Set environment
# (exported from run.json env block)

# 6. Find shell (for console mode)
SHELL=""
for s in /bin/bash /bin/ash /bin/sh; do
  [ -x "$s" ] && SHELL="$s" && break
done

# 7. Build command
ENTRYPOINT=$(json_get entrypoint[])
CMD=$(json_get cmd[])
EXEC_CMD="$ENTRYPOINT $CMD"

# 8. Console mode: spawn shell on ttyS0, run app via catatonit
CONSOLE=$(json_get console)
if [ "$CONSOLE" = "true" ] && [ -n "$SHELL" ]; then
  # Run app in background via catatonit (signal forwarding + zombie reaping)
  /sbin/catatonit -- $EXEC_CMD &
  APP_PID=$!
  # Spawn interactive shell on serial
  setsid -c $SHELL </dev/ttyS0 >/dev/ttyS0 2>/dev/ttyS0 &
  # PID 1 never exits
  wait $APP_PID 2>/dev/null
  while true; do sleep 3600; done
else
  # No console: hand off to catatonit as PID 1
  exec /sbin/catatonit -- $EXEC_CMD
fi
```

## What Gets Injected Into Rootfs at Create Time

| File | Source | Same for all VMs? |
|------|--------|-------------------|
| `/sbin/catatonit` | Static binary from host | ✅ Yes |
| `/fly/init` | Generic init script from host | ✅ Yes |
| `/fly/run.json` | Generated from OCI image config + user settings | ❌ No (per VM) |
| `/init` | Symlink → `/fly/init` | ✅ Yes |

## Kernel cmdline change

Before: `console=ttyS0 root=/dev/vda rw init=/init ip=192.168.50.220::192.168.50.1:255.255.255.0:::off`

After: `console=ttyS0 root=/dev/vda rw init=/fly/init`

Network config moves from kernel cmdline to `/fly/run.json` — parsed by our init script.

## JSON Parsing in BusyBox

BusyBox doesn't have `jq`. Options:
1. **Include static `jq` binary** (~1.5MB) — overkill
2. **Use `grep`/`sed` for flat fields** — fragile but works for simple schema
3. **Include a tiny JSON parser**: `json.sh` (~5KB shell script) or compile a micro jq
4. **Use `awk`** — can parse JSON adequately for flat/simple structures

Recommended: Option 2 for now (simple grep parsing), upgrade to a proper parser later.

## Create Flow Changes (MicroVMAdmin.php)

```php
// Instead of generating a unique /init script per VM:
// 1. Copy generic files (same for all)
copy('/usr/local/share/microvms/catatonit', "$mount/sbin/catatonit");
copy('/usr/local/share/microvms/fly-init', "$mount/fly/init");
chmod("$mount/sbin/catatonit", 0755);
chmod("$mount/fly/init", 0755);
symlink('/fly/init', "$mount/init");

// 2. Generate /fly/run.json from OCI config + user input
$runConfig = [
    'entrypoint' => $entrypoint,  // from crane config
    'cmd' => $cmd,                // from crane config
    'env' => $env,                // from crane config
    'working_dir' => $workingDir,
    'hostname' => $name,
    'network' => [
        'ip' => $ip,
        'gateway' => $gateway,
        'mask' => '255.255.255.0',
        'dns' => ['8.8.8.8', '1.1.1.1'],
    ],
    'console' => $enableConsole,
    'tty' => true,
];
file_put_contents("$mount/fly/run.json", json_encode($runConfig, JSON_PRETTY_PRINT));
```

## Benefits

1. **Generic init** — update init logic without recreating VMs (just update /fly/init on host, copy on next create)
2. **Declarative config** — /fly/run.json is readable, inspectable, modifiable
3. **Proper PID 1** — catatonit handles signals + zombies
4. **Matches Fly.io pattern** — proven at scale
5. **Separation of concerns** — VMM config (host) vs runtime config (guest)
6. **Future: swap shell init for Rust init** — /fly/init can be replaced with compiled binary later, run.json schema stays

## PLG Install Changes

Download and cache on flash:
- `catatonit` static binary (from openSUSE releases, ~30KB)
- Place at `/usr/local/share/microvms/catatonit`
- `/usr/local/share/microvms/fly-init` (our generic init script)

## Migration

Existing VMs keep working (their `/init` is baked). New VMs get the new pattern. No breaking change.

## Implementation Order

1. Download catatonit static binary in PLG
2. Create `/usr/local/share/microvms/fly-init` script
3. Update MicroVMAdmin.php create flow to inject generic files + run.json
4. Update kernel cmdline to `init=/fly/init` (remove `ip=...`)
5. Test with both CH and FC
6. Remove old per-VM init generation code


## VM Create Flow (verified)

### Raw Storage (works for any image, any size):
```
1. PHP: dd → mkfs.ext4 → resolve mount device path
2. Script: mount device → tar extract OCI → inject /fly/{init,run.json} + /sbin/catatonit → umount
3. PHP: write config JSON
```

### Thin Pool Storage (space-efficient, uses devmapper):
```
1. PHP: ctr images pull (containerd pulls OCI layers)
2. PHP: ctr images mount --snapshotter devmapper --rw (containerd mounts at /tmp/microvm-mount-$name)
3. Script: inject /fly/{init,run.json} + /sbin/catatonit (mount already done, NO mount command)
4. PHP: ctr images unmount (containerd unmounts)
5. PHP: write config JSON (stores devmapper device path)
```

### Key difference:
- **Raw**: Script handles mount+unmount (loop device)
- **Thin**: Containerd handles mount+unmount (devmapper snapshot), script only injects files

### Files injected into rootfs:
| File | Source | Purpose |
|------|--------|---------|
| `/fly/init` | `/usr/local/share/microvms/fly-init` | Generic init script (reads run.json) |
| `/fly/run.json` | Generated per VM | Entrypoint, cmd, hostname, dns, console |
| `/sbin/catatonit` | `/usr/local/share/microvms/catatonit` | PID 1 (signals + zombies) |
| `/init` → `/fly/init` | Symlink | Kernel entry point |

### Dependencies:
- `catatonit` v0.2.1 (downloaded in PLG from openSUSE releases)
- `virtiofsd` v1.13.1 (already on Unraid, for future host path sharing)
- `microvms-containerd` (started by rc.microvms, manages devmapper snapshots)
- `crane` (OCI image export for raw storage)

