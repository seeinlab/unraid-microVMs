# Review Notes

## Consistency Check ✅

- All docs reference `microvms` (lowercase) for paths — consistent
- VMM terminology used throughout (not "engine") — consistent
- Config filename determines VMM — documented and implemented consistently
- Event hooks match the boot sequence described in design-patterns.md

## Completeness Gaps

| Area | Status | Notes |
|------|--------|-------|
| FC thin pool + network | Partially tested | FC boots with thin pool but LAN connectivity untested |
| Storage tab (prune) | Planned | "Prune Unused Images" button not implemented |
| Per-VM logs button | Not implemented | Should show /var/log/microvms/{vmm}/{name}.log |
| TLS/auth for flintlockd | Deferred | Planned but not priority |
| Max memory (CH hotplug) | Not in config | Hardcoded as memory×2 in rc.microvms |
| macvtap networking | Label only | Form shows "macvtap" but actual implementation uses TAP+bridge |
| Multi-layer image dedup | Works | containerd handles it, but not surfaced in UI |

## Known Issues

1. **ACPI shutdown** fails on VMs without acpid (nginx/alpine images) — Force Stop works
2. **Containerd BoltDB** can be slow on FUSE filesystem (timeout 30s)
3. **Stale BoltDB locks** after unclean shutdown require manual cleanup

## Recommendations

1. Add automated tests for create/start/stop/delete lifecycle
2. Implement the Storage tab with image/snapshot inventory
3. Add FC network verification (same init injection as CH)
4. Consider adding health checks for running VMs (periodic ping/curl)
