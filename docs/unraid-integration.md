# Unraid Integration Guide

## Plugin System Overview

### How Unraid Plugins Work

```
User installs PLG URL via Community Apps or manual install
  → Unraid downloads .plg file to /boot/config/plugins/NAME.plg
  → PLG executes: downloads files, extracts, runs install script
  → On every boot: PLG re-executes (binaries copied to RAM, services started)
  → On remove: PLG removal section runs (cleanup)
```

### File Locations

| Location | Persists Reboot? | Purpose |
|----------|:---:|---------|
| `/boot/config/plugins/NAME/` | ✅ | Binary cache, config files |
| `/boot/config/plugins/NAME.plg` | ✅ | Plugin definition |
| `/usr/local/emhttp/plugins/NAME/` | ❌ | WebGUI pages, PHP, JS (extracted each boot) |
| `/usr/local/bin/` | ❌ | Binaries (copied each boot from flash) |
| `/etc/rc.d/rc.NAME` | ❌ | Service script (copied each boot) |
| `/var/run/NAME.pid` | ❌ | PID file (runtime) |
| `/mnt/user/appdata/NAME/` | ✅ | User data (on array/cache) |

### Key Constraint: Flash is noexec (since Unraid 6.8)
Binaries stored on `/boot/` cannot be executed directly. Must copy to RAM location first:
```bash
cp /boot/config/plugins/microvm.manager/bin/cloud-hypervisor /usr/local/bin/
chmod +x /usr/local/bin/cloud-hypervisor
```

## Service Management Pattern

### How Docker is managed (built-in, for reference)
- Config: `/boot/config/docker.cfg` → `DOCKER_ENABLED="yes"`
- Settings page: form POST → `/update.php` → writes cfg → calls `emcmd cmdStatus=Apply`
- `emhttpd` (closed-source) reads cfg and calls `/etc/rc.d/rc.docker start`
- **Cannot replicate** — emhttpd only handles Docker/libvirt internally

### How Plugins manage daemons (Tailscale pattern — what we use)
- Config: `/boot/config/plugins/tailscale/tailscale.cfg`
- PLG install: extracts binary, symlinks, calls `restart.sh`
- Service script: `/etc/rc.d/rc.tailscale` with start/stop/status
- Settings page: form POST → `/update.php` → writes cfg → `#command` calls rc script
- **This is our pattern** ✅

### rc.d Script Template

```bash
#!/bin/bash
# /etc/rc.d/rc.microvm

CFGFILE="/boot/config/plugins/microvm.manager/microvm.manager.cfg"
PIDFILE="/var/run/microvm-manager.pid"

microvm_running() {
  [ -f $PIDFILE ] && kill -0 $(cat $PIDFILE) 2>/dev/null
}

microvm_start() {
  if microvm_running; then echo "Already running"; return; fi
  source $CFGFILE 2>/dev/null
  [ "$SERVICE" != "enable" ] && return
  
  # Wait for array if needed
  while [ ! -d "/mnt/user" ]; do sleep 2; done
  
  # Create TAPs
  for vmdir in ${VMDIR}/*/; do
    [ -f "$vmdir/config.json" ] || continue
    name=$(basename $vmdir)
    ip tuntap add "tap-$name" mode tap 2>/dev/null
    ip link set "tap-$name" master ${BRIDGE:-br0} 2>/dev/null
    ip link set "tap-$name" up 2>/dev/null
  done
  
  # Optional: Start containerd + flintlockd
  # ...
  
  # Autostart VMs
  if [ "$AUTOSTART" = "yes" ]; then
    for vmdir in ${VMDIR}/*/; do
      [ -f "$vmdir/config.json" ] || continue
      # start each VM...
    done
  fi
}

microvm_stop() {
  # Graceful shutdown all VMs (ACPI)
  for sock in /tmp/microvm-*.sock; do
    [ -S "$sock" ] && ch-remote --api-socket "$sock" power-button 2>/dev/null
  done
  sleep 5
  pkill -f "cloud-hypervisor.*microvm" 2>/dev/null
  
  # Cleanup TAPs
  ip link show | grep -oP 'tap-\w+' | while read tap; do
    ip link del "$tap" 2>/dev/null
  done
  
  rm -f $PIDFILE
}

case "$1" in
  start)   microvm_start ;;
  stop)    microvm_stop ;;
  restart) microvm_stop; sleep 1; microvm_start ;;
  status)  microvm_running && echo "Running" || echo "Stopped" ;;
  *)       echo "Usage: $0 {start|stop|restart|status}" ;;
esac
```

### Settings Page → rc Script Connection

```php
<!-- In MicroVMSettings.page -->
<form method="POST" action="/update.php" target="progressFrame">
<input type="hidden" name="#file" value="microvm.manager/microvm.manager.cfg">
<input type="hidden" name="#section" value="general">
<input type="hidden" name="#command" value="/etc/rc.d/rc.microvm">
<input type="hidden" name="#arg[1]" value="restart">
```

Flow: User clicks Apply → update.php saves cfg to flash → executes `/etc/rc.d/rc.microvm restart`

## References

- Unraid Plugin Dev Docs: https://plugin-docs.mstrhakr.com/
- PLG file format: https://plugin-docs.mstrhakr.com/docs/plg-file.html
- Flash Security (noexec): https://docs.unraid.net/unraid-os/manual/security/flash-drive/
- Forum - persist scripts: https://forums.unraid.net/topic/75599
- Forum - go file: https://forums.unraid.net/topic/143744
- Forum - custom service: https://forums.unraid.net/topic/114765
- DeepWiki Service Scripts: https://deepwiki.com/unraid/webgui/7.1-service-scripts
- ZFS Master plugin (reference): https://github.com/IkerSaint/ZFS-Master-Unraid
- Tailscale plugin (reference): https://github.com/dkaser/unraid-tailscale
