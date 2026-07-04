# Session Resume Context — microVM Liquidmetal Unraid Plugin

## Date: 2026-07-01 to 2026-07-04
## Project: C:\Users\seein\Workspaces\github\unraid-microVMs

---

## Current State (Refactored — microvm.liquidmetal)

### Plugin Deployed on Unraid (192.168.50.6)
- **Plugin name**: `microvm.liquidmetal`
- **Version**: 2026.07.04.1 (fresh start, orchestrated mode)
- **SSH**: `ssh -i ~/.ssh/mastervault root@192.168.50.6`
- **Shell**: Use bash script files to avoid Windows quoting issues with SSH

### Services Running ✅
- **Thin Pool**: `/dev/mapper/microvm-thinpool` (50GB sparse data + 500MB meta, loop-backed)
- **flintlock-containerd**: v1.7.27, socket at `/var/run/microvm/containerd.sock`, devmapper snapshotter
- **flintlockd**: v0.9.1, gRPC at `0.0.0.0:9090`, bridge br0

### Installed Binaries on Unraid
- `/usr/local/bin/cloud-hypervisor` (v52.0)
- `/usr/local/bin/ch-remote` (v52.0)
- `/usr/local/bin/firecracker` (v1.16.0)
- `/usr/local/bin/flintlockd` (v0.9.1)
- `/usr/local/bin/flintlock-containerd` (v1.7.27)
- `/usr/local/bin/crane` (v0.21.7)
- `/usr/local/bin/ttyd` (v1.7.7)
- `/usr/local/bin/microvm-console`
- All cached on flash: `/boot/config/plugins/microvm.liquidmetal/`

### WebGUI Pages
- **Tab**: `microVM` (Tasks menu, position 65)
- **Sub-tabs**: microVMs (list), rootFS, Usage Statistics
- **Settings**: Settings → microVM Liquidmetal
- **Cond**: All pages gated on `is_file('/usr/local/bin/flintlockd')`

---

## Architecture (Orchestrated Mode — LIVE)

```
┌─────────────────────────────────────────────────────────────┐
│                    Unraid WebGUI                             │
│  microVM tab → MicroVMAdmin.php (AJAX backend)              │
└─────────────────────┬───────────────────────────────────────┘
                      │ gRPC (localhost:9090)
                      ▼
┌─────────────────────────────────────────────────────────────┐
│                    flintlockd v0.9.1                         │
│  - gRPC endpoint: 0.0.0.0:9090                              │
│  - Default provider: cloudhypervisor                         │
│  - Bridge: br0, State: /var/run/microvm/flintlockd-state    │
└────────────┬──────────────────────┬─────────────────────────┘
             │                      │
             ▼                      ▼
┌────────────────────┐   ┌────────────────────────────────────┐
│  Cloud Hypervisor  │   │  Firecracker v1.16.0               │
│  v52.0             │   │                                    │
└────────────────────┘   └────────────────────────────────────┘
             │                      │
             ▼                      ▼
┌─────────────────────────────────────────────────────────────┐
│              flintlock-containerd v1.7.27                    │
│  Socket: /var/run/microvm/containerd.sock                    │
│  Snapshotter: devmapper (microvm-thinpool)                  │
└─────────────────────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────────┐
│           Device-Mapper Thin Pool                           │
│  /dev/mapper/microvm-thinpool                               │
│  Data: /mnt/user/microvms/thinpool/data (50GB sparse)       │
│  Meta: /mnt/user/microvms/thinpool/meta (500MB sparse)      │
└─────────────────────────────────────────────────────────────┘
```

---

## File Layout (source)

```
src/usr/local/emhttp/plugins/microvm.liquidmetal/
├── microVM.page               ← Parent tab (Tasks:65, Tabs=true)
├── MicroVMMachines.page       ← Tab 1: VM list + context menu + autostart
├── MicroVMRootFS.page         ← Tab 2: rootFS images + OCI pull + kernels
├── MicroVMStats.page          ← Tab 3: usage statistics
├── MicroVMSettings.page       ← Settings → OtherSettings
├── AddMicroVM.page            ← Create VM form
├── backend/MicroVMAdmin.php   ← AJAX command handler
├── include/common.php         ← PHP helpers (MICROVM_PLUGIN, MICROVM_RUNTIME, etc.)
├── console.html               ← xterm.js fallback
├── images/cloud-hypervisor.png
├── images/firecracker.png
├── microvm.liquidmetal.png    ← Settings icon
├── microvm.liquidmetal.svg    ← SVG source
├── start.sh, stop.sh, restart.sh
├── rc.microvm                 ← Service script (included in tgz)
└── microvm-console            ← PTY helper
src/usr/local/etc/rc.d/rc.microvm  ← Service script (canonical)
src/usr/local/bin/microvm-console  ← PTY helper (canonical)
plugin/microvm.liquidmetal.plg     ← PLG installer
```

---

## Path Layout (on Unraid)

```
Binaries (ephemeral, /usr/local/bin/):
  cloud-hypervisor, ch-remote, firecracker, flintlockd, flintlock-containerd, crane, ttyd

Kernels (persistent):
  /mnt/user/system/microvm/kernels/cloud-hypervisor/vmlinux  (not yet downloaded)
  /mnt/user/system/microvm/kernels/firecracker/vmlinux       (not yet downloaded)

Containerd data (persistent):
  /mnt/user/system/microvm/containerd/

VM data (persistent):
  /mnt/user/microvms/{vm-name}/config.json
  /mnt/user/microvms/{vm-name}/vm.log
  /mnt/user/microvms/{vm-name}/snapshots/

Thin pool (persistent sparse files):
  /mnt/user/microvms/thinpool/data    (50GB sparse)
  /mnt/user/microvms/thinpool/meta    (500MB sparse)
  /dev/mapper/microvm-thinpool        (dm device, recreated each boot)

Runtime (ephemeral):
  /var/run/microvm/containerd.sock
  /var/run/microvm/flintlockd.pid
  /var/run/microvm/containerd.pid
  /var/run/microvm/flintlockd-state/
  /var/run/microvm/containerd-config.toml

Flash cache (persistent):
  /boot/config/plugins/microvm.liquidmetal/
  ├── cloud-hypervisor, ch-remote, firecracker
  ├── flintlockd, flintlock-containerd
  ├── crane, ttyd
  └── microvm.liquidmetal.cfg
```

---

## Boot Sequence (Verified Working ✅)

```
rc.microvm start:
  [1/6] modprobe dm_thin_pool
  [2/6] Setup thin pool (truncate sparse → losetup → dmsetup create)
  [3/6] Start flintlock-containerd (devmapper snapshotter config)
  [4/6] Start flintlockd run (gRPC, --insecure, cloudhypervisor provider)
  [5/6] Create TAP interfaces
  [6/6] Autostart VMs
```

---

## Build & Deploy Command

```bash
cd /c/Users/seein/Workspaces/github/unraid-microVMs && \
rm -rf build && mkdir -p build/microvm.liquidmetal/backend build/microvm.liquidmetal/include build/microvm.liquidmetal/images && \
cp src/usr/local/emhttp/plugins/microvm.liquidmetal/*.page build/microvm.liquidmetal/ && \
cp src/usr/local/emhttp/plugins/microvm.liquidmetal/*.sh build/microvm.liquidmetal/ && \
cp src/usr/local/emhttp/plugins/microvm.liquidmetal/*.png build/microvm.liquidmetal/ && \
cp src/usr/local/emhttp/plugins/microvm.liquidmetal/*.svg build/microvm.liquidmetal/ 2>/dev/null; \
cp src/usr/local/emhttp/plugins/microvm.liquidmetal/*.html build/microvm.liquidmetal/ 2>/dev/null; \
cp src/usr/local/emhttp/plugins/microvm.liquidmetal/images/* build/microvm.liquidmetal/images/ && \
cp -r src/usr/local/emhttp/plugins/microvm.liquidmetal/include/* build/microvm.liquidmetal/include/ && \
cp -r src/usr/local/emhttp/plugins/microvm.liquidmetal/backend/* build/microvm.liquidmetal/backend/ && \
cp src/usr/local/etc/rc.d/rc.microvm build/microvm.liquidmetal/rc.microvm && \
cp src/usr/local/bin/microvm-console build/microvm.liquidmetal/ && \
cd build && tar -czf ../plugin/microvm.liquidmetal-2026.07.04.1.tgz microvm.liquidmetal && cd .. && rm -rf build && \
echo 'tgz built' && \
cat plugin/microvm.liquidmetal-2026.07.04.1.tgz | ssh -i ~/.ssh/mastervault root@192.168.50.6 \
  'cat > /tmp/p.tgz && rm -rf /usr/local/emhttp/plugins/microvm.liquidmetal && tar -xf /tmp/p.tgz -C /usr/local/emhttp/plugins/ && chmod +x /usr/local/emhttp/plugins/microvm.liquidmetal/*.sh /usr/local/emhttp/plugins/microvm.liquidmetal/rc.microvm && cp /usr/local/emhttp/plugins/microvm.liquidmetal/rc.microvm /etc/rc.d/rc.microvm && cp /usr/local/emhttp/plugins/microvm.liquidmetal/microvm-console /usr/local/bin/microvm-console && chmod +x /usr/local/bin/microvm-console && rm /tmp/p.tgz && echo deployed'
```

---

## What Works ✅
- Orchestrated boot: thin pool + containerd + flintlockd
- Clean stop/teardown (reverse order)
- Status command showing all services
- WebGUI pages registered (microVM tab, Settings)
- PHP backend loads config correctly
- All 7 binaries present and verified
- Context menu (preserved from old version)
- Snapshot management (preserved)
- Serial console (preserved)
- Log viewer (preserved)
- Autostart (preserved)
- Resize (preserved, CH only)
- RootFS management page (preserved)
- Add VM form (preserved)
- Usage Statistics (preserved)
- Settings page with health checks (flintlockd, containerd, thin pool, KVM)

---

## Known Issues / Remaining
- [ ] Kernels not downloaded yet (need vmlinux for CH and FC)
- [ ] No VMs created yet (fresh install)
- [ ] WebGUI: need to log into Unraid WebGUI to visually verify tab appearance
- [ ] flintlockd gRPC: not yet tested with actual VM creation via gRPC
- [ ] WebGUI still uses direct CH/FC start_vm (not yet using flintlockd gRPC API)
- [ ] PLG installer not tested (manual install so far)

---

## Next Priorities
1. Download kernels to /mnt/user/system/microvm/kernels/
2. Create a test VM via the WebGUI to verify end-to-end
3. Integrate gRPC client (PHP → flintlockd) for VM lifecycle
4. PLG installer testing (full clean install from scratch)
5. nchan real-time VM status
6. macvtap support

---

## Important Notes
- **flintlockd provider names**: `cloudhypervisor` (no hyphen), `firecracker`
- **flintlockd command**: `flintlockd run [flags]` (not direct flags)
- **Line endings**: Always use LF. `.gitattributes` enforces this.
- **Windows quoting**: Use bash script files for complex SSH commands (avoid inline quoting)
- **Docker containerd**: At `/var/run/docker/containerd/` — DO NOT touch. Our containerd is `flintlock-containerd` at `/var/run/microvm/containerd.sock`
