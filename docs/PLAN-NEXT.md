# Next Session Plan

## ✅ COMPLETED (this session)

### Settings Page Redesign — DONE
- Sub-page tabs: General | Cloud Hypervisor | Firecracker | Liquidmetal
- Status box with tree display (+--- microvms hierarchy)
- Service buttons outside form (no markdown interference)
- `pidof` for reliable process detection (not `pgrep -f`)
- `$_REQUEST` for AJAX params (not `$_POST` which was empty in sub-pages)
- `view_log` uses service name mapping (not file paths in AJAX)
- Buttons: Stop/Start/Restart/View Log per service
- Enable/Disable per VMM with Apply button
- Devmapper disable guard (blocks if VMs use thin pool)
- Auto-enable VMM when set as default
- KVM/libvirt dependency check on boot

### Key Bug Fixes
- `pgrep -f microvms-containerd` matched Docker's containerd or self → `pidof`
- `$_POST` params empty in sub-page context → `$_REQUEST`
- Registry PID file empty → `pidof crane` for detection, `pgrep + kill` for stop
- Buttons inside `markdown="1"` form render as block → moved outside form

## Remaining

### Priority 2: Option C — ctr snapshots replace dmsetup
Replace `dmsetup` calls with `ctr snapshots` commands.

### Priority 5: Security (deferred)
- TLS for flintlockd
- Basic auth token

### Priority 6: Clean Up
- Remove old `microvm.liquidmetal-*.tgz`
- Test full PLG install from clean state
- Update README.md
