# Next Session Plan

## ✅ ALL COMPLETED

- Settings page: sub-page tabs, status tree, service controls
- Event hooks: array_started / stopping_svcs (no boot corruption)
- PLG installer: full cycle verified (install/uninstall/reboot)
- ctr snapshots (Option C): verified working with devmapper
- FC binary v1.16.1: boots successfully
- VMM detection: by filename everywhere
- VM list: vCPUs, IP, TAP display from config
- Add form: VMM selector linked to enabled VMMs, macvtap networking
- tap_id reuse: lowest available ID assigned

## Remaining

1. **CH LAN connectivity** — TAP networking not reaching LAN (IP in cmdline but no route?)
2. **TLS/auth for flintlockd** — deferred
3. **Per-VM Logs button** — show /var/log/microvms/{vmm}/{name}.log
4. **Update root README.md** — for GitHub
