# Next Session Plan

## Priority 1: Settings Page Redesign (Service Status UI)

Redesign MicroVMsSettings.page service status to match Unraid's **Settings/ManagementAccess** pattern:
- Grid/table layout showing each service with status indicator (green/red dot)
- Start/Stop/Restart buttons per service
- Like Unraid's SSH, Telnet, API Status display

Services to show:
| Service | Port/Socket | Control |
|---------|-------------|---------|
| microvms-containerd | /var/run/microvms/containerd.sock | Start/Stop |
| crane registry | 127.0.0.1:5050 | Start/Stop |
| flintlockd | 0.0.0.0:9090 | Start/Stop |
| microvms-thinpool | /dev/mapper/microvms-thinpool | Status only |

Reference: Unraid Settings → Management Access → API Status section

## Priority 2: Option C — ctr snapshots replace dmsetup

Replace `dmsetup` calls with `ctr snapshots` commands:
- `create_thin_rootfs()` → `ctr -a $SOCK -n {vmm} snapshots prepare "vm-{name}" ""`
- `delete_thin_rootfs()` → `ctr -a $SOCK -n {vmm} snapshots remove "vm-{name}"`
- `activate_thin_rootfs()` → `ctr -a $SOCK -n {vmm} snapshots mounts`
- Remove `next_thin_device_id()` (containerd manages IDs)
- Remove `THIN_DEVICE_ID_BASE` constant
- Update MicroVMAdmin.php to not pass device_id

## Priority 3: Per-VMM Enable/Disable

Settings toggle for each VMM:
- Enable Cloud Hypervisor: Yes/No (requires kernel downloaded)
- Enable Firecracker: Yes/No (requires kernel downloaded)
- Only show enabled VMMs in Add VM form
- Default VMM = first enabled one

## Priority 4: Kernel Auto-Download

On first install or when VMM enabled:
- Download CH kernel (PVH vmlinux)
- Download FC kernel (vmlinux)
- Show download progress in Settings
- Push to crane registry after download

## Priority 5: Clean Up

- Remove old `microvm.liquidmetal-*.tgz` from plugin/ directory (local only)
- Test full PLG install from clean state
- Verify all paths work after fresh boot

## Current State Summary

```
Plugin: microvms (fully renamed, deployed, running)
Service: rc.microvms
Containerd: microvms-containerd (always starts)
Liquidmetal: flintlockd + crane (optional, for remote API)
UI: Direct mode only (no flintlockd in UI path)
Config: infra-as-code JSON (cloud-hypervisor.json / firecracker.json)
Storage: Thin pool (devmapper) or Raw file (user choice)
Logs: /var/log/microvms/{service}.log + {vmm}/{name}.log
```

## Files to Modify

- `src/usr/local/emhttp/plugins/microvms/MicroVMsSettings.page` — Service status grid
- `src/usr/local/etc/rc.d/rc.microvms` — ctr snapshot commands
- `src/usr/local/emhttp/plugins/microvms/backend/MicroVMAdmin.php` — remove device_id passing
- `src/usr/local/emhttp/plugins/microvms/AddMicroVMs.page` — per-VMM enable filter
