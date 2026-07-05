# Next Session Plan

## ✅ COMPLETED (this session)

- Settings page: sub-page tabs (General, Cloud Hypervisor, Firecracker, Liquidmetal)
- Status box with tree display
- Service buttons (Stop/Start/Restart/View Log) working via AJAX
- Process detection: `pidof` (not pgrep)
- Backend: `$_REQUEST` (not `$_POST`)
- Devmapper optional (enable/disable)
- Auto-enable VMM when set as default
- PLG installer: clean install from UI verified ✓
- All download URLs fixed (flintlockd_amd64, grpcurl x86_64)
- Removed libvirtd dependency (only /dev/kvm needed)
- PLG file: `microvms.plg`, folder: `microvms/`

## Remaining

### Priority 2: Option C — ctr snapshots replace dmsetup
Replace `dmsetup` calls with `ctr snapshots` commands.

### Priority 5: Security (deferred)
- TLS for flintlockd
- Basic auth token

### Priority 6: Clean Up
- Update README.md (root level)
- Rebuild tgz for release
- Test uninstall + reinstall cycle
