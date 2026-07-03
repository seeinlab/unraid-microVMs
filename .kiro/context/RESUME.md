# Session Resume Context — microVM Manager Unraid Plugin

## Date: 2026-07-01 to 2026-07-03
## Project: C:\Users\seein\Workspaces\github\unraid-microVMs

---

## Current State

### Plugin Deployed on Unraid (192.168.50.6)
- **Version**: 68+ iterations (microvm.manager-2026.07.03.66.tgz latest build)
- **Git commits**: 60+ commits on main branch (latest: d1cf7b7)
- **SSH**: `ssh -i ~/.ssh/mastervault root@192.168.50.6`
- **Shell**: All commands use `& "C:\Program Files\Git\bin\bash.exe" -c "..."` (Windows + Git Bash)

### Running VMs on Unraid
- **nginx-ch** — Cloud Hypervisor v52.0, IP 192.168.50.220, nginx serving, serial console on /dev/ttyS0
- **nginx-fc** — Firecracker v1.16.0, IP 192.168.50.221, nginx serving

### Installed Binaries on Unraid
- `/usr/local/bin/cloud-hypervisor` (v52.0, 5.5MB static)
- `/usr/local/bin/ch-remote` (v52.0)
- `/usr/local/bin/firecracker` (v1.16.0)
- `/usr/local/bin/crane` (v0.21.7, OCI image tool)
- `/usr/local/bin/ttyd` (v1.7.7, web terminal)
- `/usr/local/bin/microvm-console` (bidirectional PTY helper)
- All cached on flash: `/boot/config/plugins/microvm.manager/`

### Kernel Layout
```
/mnt/user/microvms/kernels/
├── cloud-hypervisor/vmlinux  (60MB, kernel 6.2.0+)
└── firecracker/vmlinux       (37MB, kernel 5.10.225)
```

### Plugin Config
- `/boot/config/plugins/microvm.manager/microvm.manager.cfg`
- VMDIR="/mnt/user/microvms"
- BRIDGE="br0"
- DEFAULT_ENGINE="cloud-hypervisor"

---

## Architecture

### File Layout (source)
```
src/usr/local/emhttp/plugins/microvm.manager/
├── MicroVMs.page              ← Parent tab (Tabs=true)
├── MicroVMMachines.page       ← Tab 1: VM list + context menu + switchButton autostart
├── MicroVMRootFS.page         ← Tab 2: rootFS images + OCI pull + kernel list
├── MicroVMStats.page          ← Tab 3: usage statistics
├── MicroVMSettings.page       ← Settings → OtherSettings
├── AddMicroVM.page            ← Create VM form (dl/dt/dd layout, engine selector)
├── backend/MicroVMAdmin.php   ← AJAX command handler (all commands)
├── include/common.php         ← PHP helpers (list, start, stop, status, snapshots)
├── console.html               ← Standalone xterm.js page (fallback)
├── images/cloud-hypervisor.png  (official GitHub org logo, rounded corners)
├── images/firecracker.png       (official flame logo, transparent)
├── microvm.manager.png        ← Settings icon (LiquidMetal droplet from SVG)
├── microvm.manager.svg        ← LiquidMetal SVG source
├── start.sh, stop.sh, restart.sh
└── microvm-console            ← Bidirectional PTY script (exec 3<>PTY)
src/usr/local/etc/rc.d/rc.microvm  ← Service script
src/usr/local/bin/microvm-console  ← PTY helper binary
```

### Key Technical Details

1. **Log path**: VM logs at `VMDIR/NAME/vm.log` (persistent). Fallback to `/var/log/microvm-NAME.log`.

2. **Serial console (CH only)**: `--serial pty` → PTY path in log → ttyd on unix socket (`/var/tmp/microvm-NAME.console.sock`) → nginx proxies at `/logterminal/microvm-NAME.console/`

3. **Log viewer**: ttyd on unix socket (`/var/tmp/microvm-NAME.log.sock`) → proxied at `/logterminal/microvm-NAME.log/`

4. **Context menu**: Uses Unraid's built-in `context.js` (bundled in dynamix.js). Pattern: `context.attach('#vm-NAME', opts)`

5. **Firecracker stop**: Uses `kill PID` (no ACPI support). CH uses `ch-remote power-button`.

6. **TAP display**: Shows `tap-NAME@br0` format.

7. **Autostart**: jQuery switchButton (same as Docker/VMs pages).

8. **Resize (CH only)**: swal input dialogs, updates config.json after success.

9. **Init script**: CH gets serial shell on ttyS0 (`TERM=linux sh`). FC just runs nginx (no interactive console).

---

## What Works ✅
- Create VM from OCI image (crane pull → rootfs)
- Start/Stop VMs (CH: ACPI, FC: kill)
- Force Stop (kill -9)
- Live resize CPU/RAM (CH only, updates config.json)
- Snapshot (CH: pause→snap→resume)
- Snapshot management (list/restore/delete via swal)
- Remove VM (blocked if has snapshots)
- Serial console (CH: ttyd + unix socket + nginx proxy)
- Log viewer (ttyd + tail -f via logterminal)
- Settings page with health checks
- Engine selector on Add page (CH or FC with logos)
- Context menu per VM (Unraid native context.js)
- switchButton autostart toggle
- RootFS tab (list images, pull OCI, delete, kernel engine column)
- Usage Statistics tab
- Both engines use own kernel subfolder
- TAP display as tap-NAME@br0

---

## Known Issues / Remaining
- [ ] Console shows `^[[32;5R` once at start (busybox DSR query, cosmetic)
- [ ] Add VM form: buttons now horizontal but might need more CSS testing
- [ ] No serial console for Firecracker (only log viewer)
- [ ] Cloud Hypervisor logo has text (not ideal at 32x32 but official)
- [ ] LiquidMetal settings icon slightly cropped (SVG viewBox vs render)

---

## Build & Deploy Command (one-liner)
```bash
cd /c/Users/seein/Workspaces/github/unraid-microVMs && rm -rf build && mkdir -p build/microvm.manager/backend build/microvm.manager/include build/microvm.manager/images && cp src/usr/local/emhttp/plugins/microvm.manager/*.page build/microvm.manager/ && cp src/usr/local/emhttp/plugins/microvm.manager/*.sh build/microvm.manager/ && cp src/usr/local/emhttp/plugins/microvm.manager/*.png build/microvm.manager/ && cp src/usr/local/emhttp/plugins/microvm.manager/*.svg build/microvm.manager/ 2>/dev/null; cp src/usr/local/emhttp/plugins/microvm.manager/*.html build/microvm.manager/ 2>/dev/null; cp src/usr/local/emhttp/plugins/microvm.manager/images/* build/microvm.manager/images/ && cp -r src/usr/local/emhttp/plugins/microvm.manager/include build/microvm.manager/ && cp -r src/usr/local/emhttp/plugins/microvm.manager/backend build/microvm.manager/ && cp src/usr/local/etc/rc.d/rc.microvm build/microvm.manager/rc.microvm && cp src/usr/local/bin/microvm-console build/microvm.manager/ && cd build && tar -czf ../plugin/microvm.manager-VERSION.tgz microvm.manager && cd .. && rm -rf build && cat plugin/microvm.manager-VERSION.tgz | ssh -i ~/.ssh/mastervault root@192.168.50.6 'cat > /tmp/p.tgz && rm -rf /usr/local/emhttp/plugins/microvm.manager && tar -xf /tmp/p.tgz -C /usr/local/emhttp/plugins/ && chmod +x /usr/local/emhttp/plugins/microvm.manager/*.sh /usr/local/emhttp/plugins/microvm.manager/rc.microvm && cp /usr/local/emhttp/plugins/microvm.manager/rc.microvm /etc/rc.d/rc.microvm && cp /usr/local/emhttp/plugins/microvm.manager/microvm-console /usr/local/bin/microvm-console && chmod +x /usr/local/bin/microvm-console && rm /tmp/p.tgz && echo ok'
```

---

## Next Priorities
1. PLG installer for Community Applications
2. nchan real-time VM status (no page refresh)
3. Add VM form refinements (match VMs page exactly)
4. macvtap support (tap0@br0 naming)
5. VM edit page
6. Import/export VM
