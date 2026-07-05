# Progress Log — microVMs Plugin for Unraid

## v2026.07.05 — Current (Verified Working)

### ✅ Full Plugin Lifecycle Verified
- **Install from UI**: Downloads binaries, extracts, installs — no service start at boot ✓
- **Array start**: `event/array_started` fires → `rc.microvms start` → containerd starts ✓
- **Array stop**: `event/stopping_svcs` fires → `rc.microvms stop` → clean shutdown ✓
- **Uninstall from UI**: Stops services, removes files, preserves user data ✓
- **Reboot**: Plugin reinstalls from flash, waits for array, then starts ✓
- **No Unraid corruption**: Does NOT access /mnt/user/ before array is online ✓

### Architecture
```
WebGUI → MicroVMAdmin.php → rc.microvms → cloud-hypervisor/firecracker (Direct Mode)
                                              ↑
                              microvms-containerd (devmapper thin pool for rootfs)

Remote API → grpcurl → flintlockd:9090 → containerd → thin pool → CH/FC (Liquidmetal Mode)
                                              ↑
                              crane registry (127.0.0.1:5050, OCI images)
```

### Critical Design Rules

1. **PLG install (boot) must NEVER access /mnt/user/** — array not online yet
2. **Services start via event/array_started** — not in PLG install script
3. **Process detection uses `pidof`** — not `pgrep -f` (Docker containerd false positive)
4. **AJAX backend uses `$_REQUEST`** — not `$_POST` (empty in sub-page tab context)
5. **KVM is independent of libvirt** — only needs /dev/kvm kernel device
6. **Liquidmetal disabled by default** — optional remote automation layer
7. **Devmapper optional** — can run with raw file storage only

### Boot Sequence

```
BOOT (PLG install via rc.local):
  ├── Download/cache binaries to /boot/config/plugins/microvms/
  ├── Extract tgz to /usr/local/emhttp/plugins/microvms/
  ├── Install binaries to /usr/local/bin/
  ├── Create symlink /etc/rc.d/rc.microvms
  ├── Create log dirs /var/log/microvms/
  └── DO NOT start services, DO NOT touch /mnt/user/

ARRAY START (event/array_started):
  ├── Source config from /boot/config/plugins/microvms/
  ├── Check SERVICE="enable"
  └── /etc/rc.d/rc.microvms start
        ├── [pre] Check /dev/kvm
        ├── Create dirs under /mnt/user/ (safe now)
        ├── [1-2] If DEVMAPPER=enable: dm_thin_pool + thinpool setup
        ├── [3/7] Start microvms-containerd
        ├── [4/7] Start crane registry (if FLINTLOCKD=enable)
        ├── [5/7] Start flintlockd (if FLINTLOCKD=enable)
        ├── [6/7] Re-create TAP interfaces
        └── [7/7] Autostart microVMs

ARRAY STOP (event/stopping_svcs):
  └── /etc/rc.d/rc.microvms stop
        ├── Stop all running VMs
        ├── Stop flintlockd
        ├── Stop crane registry
        ├── Stop microvms-containerd
        ├── Teardown thin pool
        └── Remove TAP interfaces
```

### Settings Page (Sub-page Tabs)

| Tab | Content |
|-----|---------|
| General | Status tree, containerd control, Enable, Storage, Bridge, VMM defaults, devmapper |
| Cloud Hypervisor | Enable CH, Kernel URL |
| Firecracker | Enable FC, Kernel URL |
| Liquidmetal | flintlockd/registry control, Enable, Crane storage, gRPC port, flags |

### Status Tree Display
```
+--- microvms
++-- kvm                : /dev/kvm available
++-- vmm
+++--- cloud-hypervisor : available — cloud-hypervisor v52.0 (Linux 6.2.0)
+++--- firecracker      : available — Firecracker v1.16.0 (Linux 5.10.225)
++-- runtime
+++--- containerd       : running — containerd v1.7.27
++-- option
+++--- devmapper        : active/disabled
++-- liquidmetal        : ready/failed/disabled
+++--- flintlockd       : running/stopped
+++--- registry         : running/stopped
```

### Files on Flash (persist reboots)
```
/boot/config/plugins/microvms.plg              PLG definition
/boot/config/plugins/microvms/
  microvms.controlplane.cfg                    Settings
  microvms-2026.07.05.1.tgz                   Plugin package
  cloud-hypervisor                             Cached binary
  ch-remote                                   Cached binary
  firecracker                                 Cached binary
  microvms-containerd                         Cached binary
  flintlockd                                  Cached binary
  crane                                       Cached binary
  ttyd                                        Cached binary
  grpcurl                                     Cached binary
```

### Files on Array (persist, user data)
```
/mnt/user/microvms/                            VM configs + rootfs
/mnt/user/system/microvms/
  cloud-hypervisor/kernels/vmlinux             CH kernel
  firecracker/kernels/vmlinux                  FC kernel
  containerd/                                  Containerd state + snapshots
  crane/registry/                              OCI image registry
```

### Bugs Fixed This Session

| Bug | Root Cause | Fix |
|-----|-----------|-----|
| Unraid shares disappear after reboot | PLG accessed /mnt/user/ before array mount | Use event/array_started |
| Containerd shows "running" when stopped | `pgrep -f` matches Docker containerd | Use `pidof` |
| View Log / Stop buttons fail | `$_POST` empty in sub-page tab | Use `$_REQUEST` |
| Registry Stop fails | PID file empty, pkill pattern wrong | Direct `pidof crane` + kill |
| Firecracker shows "no binary" | `--version` outputs to stderr | Grep for version line |
| flintlockd download 404 | Binary named `flintlockd_amd64` not `flintlockd` | Fix URL |
| grpcurl download 404 | Named `x86_64` not `amd64` | Fix URL |
| Buttons render as block | Inside `markdown="1"` form | Move outside form |

### Remaining Work

1. **Option C**: Replace dmsetup with `ctr snapshots` (containerd manages all device IDs)
2. **TLS/auth for flintlockd**: Auto self-signed cert + basic auth token
3. **Kernel auto-download**: Download on first install if missing
4. **Update README.md**: Root level readme for GitHub

---

## Pre-refactor versions (microvm.manager)

### v70-v71 — FC snapshot, code cleanup
### v60-v69 — Firecracker, dual-engine, context menu, console, resize
### v50-v59 — CH snapshot/restore, OCI pull, rootFS page
### v40-v49 — Initial CH, WebGUI, TAP networking, autostart
