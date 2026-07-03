# Session Resume Context — microVM Manager Unraid Plugin

## Date: 2026-07-01 to 2026-07-03
## Project: C:\Users\seein\Workspaces\github\unraid-microVMs

---

## Current State

### Plugin Deployed on Unraid (192.168.50.6)
- **Version**: 48 iterations (microvm.manager-2026.07.03.48.tgz)
- **Git commits**: 40+ commits on main branch
- **SSH**: `ssh -i ~/.ssh/mastervault root@192.168.50.6`
- **Shell**: All commands use `& "C:\Program Files\Git\bin\bash.exe" -c "..."` (Windows + Git Bash)

### Running VMs on Unraid
- **nginx-test** — Cloud Hypervisor v52.0, IP 192.168.50.219, nginx serving
- **fc-nginx** — Firecracker v1.16.0, IP 192.168.50.220, nginx serving (started via --config-file)
- **nginx2** — Firecracker, IP 192.168.50.189 (may be stopped)

### Installed Binaries on Unraid
- `/usr/local/bin/cloud-hypervisor` (v52.0, 5.5MB static)
- `/usr/local/bin/ch-remote` (v52.0)
- `/usr/local/bin/firecracker` (v1.16.0)
- `/usr/local/bin/crane` (v0.21.7, OCI image tool)
- All cached on flash: `/boot/config/plugins/microvm.manager/`

### Kernel Layout
```
/mnt/user/microvms/kernels/
├── cloud-hypervisor/vmlinux  (61MB, kernel 6.2.0+)
└── firecracker/vmlinux       (37MB, kernel 5.10.225)
```
No shared `kernels/vmlinux` — each engine uses its own subfolder.

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
├── MicroVMs.page              ← Parent tab (Tasks:65, Tabs=true)
├── MicroVMMachines.page       ← Tab 1: VM list + context menu + actions
├── MicroVMRootFS.page         ← Tab 2: rootfs images + OCI pull
├── MicroVMStats.page          ← Tab 3: usage statistics
├── MicroVMSettings.page       ← Settings → OtherSettings (markdown form)
├── AddMicroVM.page            ← Create VM form (engine selector)
├── backend/MicroVMAdmin.php   ← AJAX command handler
├── include/common.php         ← PHP helpers (list, start, stop, status)
├── images/cloud-hypervisor.png
├── images/firecracker.png
├── microvm.manager.png        ← Plugin icon (Firecracker flame)
├── start.sh, stop.sh, restart.sh
└── rc.microvm                 ← Copied to /etc/rc.d/ at install
src/usr/local/etc/rc.d/rc.microvm  ← Service script (start/stop/status/start_vm/stop_vm)
```

### Key Technical Details

1. **FUSE path issue**: Cloud Hypervisor can't read `/mnt/user/` (Unraid FUSE shfs). The `rc.microvm start_vm` has a `resolve_path()` function that searches `/mnt/cache /mnt/mtier /mnt/ztier /mnt/rtier /mnt/disk*` for the actual file.

2. **JSON escape issue**: PHP's `json_encode()` writes `\/` in paths. The `start_vm` function uses `tr -d '\\'` to strip backslashes after grep extraction.

3. **Dual engine support**:
   - CH: `cloud-hypervisor --api-socket /tmp/microvm-NAME.sock --kernel ... --disk ...`
   - FC: `firecracker --api-sock /tmp/microvm-NAME.sock --config-file /tmp/microvm-NAME-fc.json`
   - FC config generated at start time from the unified config.json

4. **Status check**:
   - CH: `ch-remote --api-socket $sock ping` (exit code 0 = running)
   - FC: `pgrep -f 'microvm-NAME'` (process exists = running)

5. **Unraid page routing**: Filenames must start with uppercase (MicroVMs.page → /MicroVMs). Top nav is always UPPERCASE (CSS). Tab labels from Title in sub-pages.

6. **Settings form**: Uses `markdown="1"` form with `label:\n: value` pattern. Buttons need `<span class="inline-block">` wrapper for horizontal layout.

7. **Commands via update.php**: Must be relative to docroot (`/plugins/microvm.manager/stop.sh`) — update.php prepends `/usr/local/emhttp/`.

---

## What Works ✅
- Create VM from OCI image (crane pull → rootfs)
- Start/Stop VMs (both CH and FC engines)
- Force Stop (kill -9)
- Live resize CPU/RAM (CH only, FC shows "not supported")
- Snapshot (CH: pause→snap→resume)
- Remove VM (blocked if has snapshots)
- Settings page with health checks (Ready/Failed)
- Engine selector on Add page (CH or FC with logos)
- Context menu per VM (click ▼ or name)
- TAP interface display
- RootFS tab (list images, pull OCI)
- Usage Statistics tab
- Both engines use own kernel subfolder
- Default Engine setting

---

## Known Issues / TODO
- [ ] Cloud Hypervisor logo has dark background (needs transparent/rounded version)
- [ ] Context menu doesn't match VMs page exactly (VMs uses click-on-name natively via addVMContext JS)
- [ ] No serial console yet
- [ ] No snapshot list/manage UI
- [ ] "Start" from WebGUI needs page reload to see status change (no nchan yet)
- [ ] Stop (graceful) for FC just kills process (FC has no ACPI)
- [ ] `nginx2` VM's rootfs was on /mnt/mtier but config still says /mnt/user — needs path fix in config.json

---

## Build & Deploy Command (one-liner)
```bash
cd /c/Users/seein/Workspaces/github/unraid-microVMs && git add -A && git commit -m 'MESSAGE' && rm -rf build && mkdir -p build/microvm.manager/backend build/microvm.manager/include build/microvm.manager/scripts build/microvm.manager/images && cp src/usr/local/emhttp/plugins/microvm.manager/*.page build/microvm.manager/ && cp src/usr/local/emhttp/plugins/microvm.manager/*.sh build/microvm.manager/ && cp src/usr/local/emhttp/plugins/microvm.manager/*.png build/microvm.manager/ && cp src/usr/local/emhttp/plugins/microvm.manager/images/* build/microvm.manager/images/ && cp -r src/usr/local/emhttp/plugins/microvm.manager/include build/microvm.manager/ && cp -r src/usr/local/emhttp/plugins/microvm.manager/backend build/microvm.manager/ && cp src/usr/local/etc/rc.d/rc.microvm build/microvm.manager/rc.microvm && cd build && tar -czf ../plugin/microvm.manager-VERSION.tgz microvm.manager && cd .. && rm -rf build && cat plugin/microvm.manager-VERSION.tgz | ssh -i ~/.ssh/mastervault root@192.168.50.6 'cat > /tmp/p.tgz && rm -rf /usr/local/emhttp/plugins/microvm.manager && tar -xf /tmp/p.tgz -C /usr/local/emhttp/plugins/ && chmod +x /usr/local/emhttp/plugins/microvm.manager/*.sh /usr/local/emhttp/plugins/microvm.manager/rc.microvm && cp /usr/local/emhttp/plugins/microvm.manager/rc.microvm /etc/rc.d/rc.microvm && rm /tmp/p.tgz && echo ok'
```

---

## Reference Repos Cloned
- `D:/github/unraid-webgui` — Unraid 7.2.7 WebGUI source (page system, update.php, emcmd)
- `D:/github/unraid-tailscale` — Official Tailscale plugin (daemon pattern, rc.d, PLG)
- `D:/github/ZFS-Master-Unraid` — ZFS Master plugin (nchan, AJAX backend, SweetAlert2)

---

## Research Completed (docs/ folder)
- architecture.md — Stack decisions, CH vs FC
- cloud-hypervisor.md — Full API reference
- diagrams.md — 9 Mermaid diagrams
- flintlock-containerd.md — Orchestration layer
- networking.md — TAP/bridge setup
- oci-to-rootfs.md — Image conversion
- plugin-development.md — Unraid PLG format
- test-results.md — All proven tests
- unraid-integration.md — rc.d, persistence
- roadmap.md — Next items

---

## Next Session Priorities
1. Fix context menu to be more like native VMs (click name opens menu)
2. Snapshot list/manage UI
3. Serial console (WebSocket + xterm.js)
4. CH logo improvement (transparent background)
5. PLG installer for Community Applications distribution
6. nchan real-time status updates (no page refresh needed)
