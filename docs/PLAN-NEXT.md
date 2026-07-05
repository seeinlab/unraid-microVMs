# Next Session Plan

## ✅ COMPLETED (this session)

### Priority 1: Settings Page Redesign — DONE
Service status grid with hierarchy:
```
● KVM                        [VM Manager]
  ● VMM (Ready/Not Ready)
    └─ Cloud Hypervisor      [Enable/Disable] [Download Kernel]
    └─ Firecracker           [Enable/Disable] [Download Kernel]
    └─ containerd            [Start/Stop] [View Log]
    └─ devmapper             [Enable/Disable]
  ● Liquidmetal              [Enable/Disable]
    └─ flintlockd            [Start/Stop] [View Log]
    └─ registry              [Start/Stop] [View Log]
```

Features:
- VMM Ready = KVM ok + 1 VMM available + containerd running
- Cannot disable VMM if microVMs defined for it
- Cannot disable devmapper if VMs use thin pool storage
- Basic View / Advanced View toggle (like VM Manager)
- Settings grouped by panel (VMM Settings, Devmapper Settings, Liquidmetal Settings)
- Flintlockd extra flags textarea (like Syslinux config)

### Priority 3: Per-VMM Enable/Disable — DONE (in Settings grid)
### Priority 4: Kernel URLs configurable — DONE (in Advanced view)

## Remaining

### Priority 2: Option C — ctr snapshots replace dmsetup
Replace `dmsetup` calls with `ctr snapshots` commands:
- `create_thin_rootfs()` → `ctr -a $SOCK -n {vmm} snapshots prepare "vm-{name}" ""`
- `delete_thin_rootfs()` → `ctr -a $SOCK -n {vmm} snapshots remove "vm-{name}"`
- Remove `next_thin_device_id()` (containerd manages IDs)

### Priority 5: Security (deferred)
- TLS for flintlockd (auto self-signed + custom cert)
- Basic auth token (auto-generated)

### Priority 6: Clean Up
- Remove old `microvm.liquidmetal-*.tgz`
- Test full PLG install from clean state
- Verify all paths work after fresh boot
