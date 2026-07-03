# Plugin Development Guide

## Based on Analysis of:
- `unraid/unraid-tailscale` (official Unraid org, most modern pattern)
- `IkerSaint/ZFS-Master-Unraid` (community plugin, simpler pattern)
- Unraid 7.2.7 webgui source

## Tailscale Pattern (Recommended - Official Unraid Standard)

### Architecture

```
Repository structure:
├── plugin/
│   └── microvm.manager.plg       ← Plugin installer XML
├── src/
│   ├── usr/local/emhttp/plugins/microvm.manager/  ← WebGUI pages/PHP
│   ├── usr/local/etc/rc.d/rc.microvm              ← Service script
│   └── install/
│       ├── doinst.sh              ← Post-install (Slackware pkg)
│       └── slack-desc             ← Package description
├── docs/
└── .github/workflows/
    └── release.yml                ← Build + package
```

### How Tailscale Manages Its Daemon

1. **Binary download**: PLG `<FILE>` element downloads `tailscale_X.Y.Z_amd64.tgz` to `/boot/config/plugins/tailscale/`
2. **Utils package**: Separate `.txz` Slackware package with PHP pages, rc script, helpers
3. **Install**: Extracts binary to `/usr/local/emhttp/plugins/tailscale/bin/`, symlinks to `/usr/local/sbin/`
4. **rc.d script**: `/usr/local/etc/rc.d/rc.tailscale` → symlinked/copied to `/etc/rc.d/rc.tailscale`
5. **Start**: PLG calls `restart.sh` which does `at now` to schedule `/etc/rc.d/rc.tailscale restart` in 5 seconds
6. **Settings**: Form POST → `/update.php` → writes `.cfg` → `#command` calls `restart.sh`
7. **Remove**: PLG removal section calls `rc.tailscale stop`, removes packages

### Key Differences from ZFS Master (simpler) Pattern

| Aspect | Tailscale (modern) | ZFS Master (simple) |
|--------|-------------------|---------------------|
| Source packaging | Slackware `.txz` package | Raw `.tgz` of plugin folder |
| PHP structure | OOP with namespaces | Flat PHP files |
| i18n | JSON locale files | Inline strings |
| Binary management | Symlinks to /usr/local/sbin/ | Direct exec from plugin dir |
| Service management | rc.d script via pkg install | No daemon needed |
| Config storage | `/boot/config/plugins/NAME/NAME.cfg` | Same |

### For MicroVM Plugin: Hybrid Approach

Use Tailscale's **daemon management pattern** (rc.d, restart.sh, proper service lifecycle)
but ZFS Master's **simpler packaging** (single .tgz, no Slackware pkg complexity) for initial development.

Graduate to full Slackware package later when the plugin matures.

## PLG File Template (MicroVM Manager)

```xml
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN>
<PLUGIN name="microvm.manager" author="YourName" version="VERSION"
  pluginURL="https://raw.githubusercontent.com/you/unraid-microVMs/main/plugin/microvm.manager.plg"
  launch="Settings/MicroVMManager"
  min="6.12.0">

<CHANGES><![CDATA[
### VERSION
- Initial release
]]></CHANGES>

<!-- Download Cloud Hypervisor static binary -->
<FILE Name="/boot/config/plugins/microvm.manager/cloud-hypervisor-v52.0">
<URL>https://github.com/cloud-hypervisor/cloud-hypervisor/releases/download/v52.0/cloud-hypervisor-static</URL>
</FILE>

<!-- Download ch-remote static binary -->
<FILE Name="/boot/config/plugins/microvm.manager/ch-remote-v52.0">
<URL>https://github.com/cloud-hypervisor/cloud-hypervisor/releases/download/v52.0/ch-remote-static</URL>
</FILE>

<!-- Download plugin package -->
<FILE Name="/boot/config/plugins/microvm.manager/microvm.manager-VERSION.tgz">
<URL>https://raw.githubusercontent.com/you/unraid-microVMs/main/microvm.manager-VERSION.tgz</URL>
</FILE>

<!-- Install script -->
<FILE Run="/bin/bash"><INLINE><![CDATA[
# Extract plugin files
tar -xf /boot/config/plugins/microvm.manager/microvm.manager-VERSION.tgz \
  -C /usr/local/emhttp/plugins 2>/dev/null

# Install binaries (flash → RAM, with exec permission)
cp /boot/config/plugins/microvm.manager/cloud-hypervisor-v52.0 /usr/local/bin/cloud-hypervisor
cp /boot/config/plugins/microvm.manager/ch-remote-v52.0 /usr/local/bin/ch-remote
chmod +x /usr/local/bin/cloud-hypervisor /usr/local/bin/ch-remote

# Install rc.d service script
cp /usr/local/emhttp/plugins/microvm.manager/rc.microvm /etc/rc.d/rc.microvm
chmod +x /etc/rc.d/rc.microvm

# Create default config
if [ ! -f /boot/config/plugins/microvm.manager/microvm.manager.cfg ]; then
cat > /boot/config/plugins/microvm.manager/microvm.manager.cfg << 'EOF'
SERVICE="enable"
VMDIR="/mnt/user/appdata/microvm"
BRIDGE="br0"
DEFAULT_CPUS="1"
DEFAULT_MEMORY="256"
AUTOSTART="no"
EOF
fi

# Start service (delayed, after array if needed)
/usr/local/emhttp/plugins/microvm.manager/restart.sh

echo "-----------------------------------------------------------"
echo " microvm.manager installed."
echo " Cloud Hypervisor: $(cloud-hypervisor --version)"
echo "-----------------------------------------------------------"
]]></INLINE></FILE>

<!-- Remove script -->
<FILE Run="/bin/bash" Method="remove"><INLINE><![CDATA[
/etc/rc.d/rc.microvm stop 2>/dev/null
rm -f /usr/local/bin/cloud-hypervisor /usr/local/bin/ch-remote
rm -f /etc/rc.d/rc.microvm
rm -rf /usr/local/emhttp/plugins/microvm.manager
rm -f /boot/config/plugins/microvm.manager/*.tgz
echo "microvm.manager removed."
]]></INLINE></FILE>

</PLUGIN>
```

## Settings Page Connection (how Apply triggers service)

```php
<!-- form in Settings page -->
<form method="POST" action="/update.php" target="progressFrame">
  <input type="hidden" name="#file" value="microvm.manager/microvm.manager.cfg">
  <input type="hidden" name="#command" value="/usr/local/emhttp/plugins/microvm.manager/restart.sh">
  <!-- form fields... -->
  <input type="submit" value="Apply">
</form>
```

`restart.sh`:
```bash
#!/bin/bash
echo "sleep 2 ; /etc/rc.d/rc.microvm restart" | at now 2>/dev/null
```

## Event Hooks

Unraid plugins can react to system events via the `event/` directory:
```
src/usr/local/emhttp/plugins/microvm.manager/event/
├── array_started      ← Script runs when array starts
├── array_stopping     ← Script runs before array stops
└── docker_started     ← Script runs when Docker starts
```

These are simple shell scripts that Unraid executes at the appropriate time.
