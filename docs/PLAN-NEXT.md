# Next Session Plan

## ✅ ALL MAJOR FEATURES COMPLETE

### Verified Working
- CH + FC: Create, Start, Stop, Delete (both Thin Pool and Raw rootFS)
- Network: LAN reachable via TAP+bridge + kernel ip= + custom /init
- OCI: Full containerd pull for thin, crane export for raw
- Init: ENTRYPOINT/CMD from image config, properly shell-escaped
- Settings: Sub-page tabs, status tree, service controls
- Plugin: Install/Uninstall/Reboot lifecycle safe
- Codebase docs: AGENTS.md + .agents/summary/ generated

## Remaining Tasks

### Priority 1: UI Polish
- [ ] Per-VM Logs button in context menu (show /var/log/microvms/{vmm}/{name}.log)
- [ ] Storage tab: image inventory, snapshot list, "Prune Unused Images"
- [ ] Fix "macvtap" label (actually uses TAP+bridge)
- [ ] Max Memory field (CH hotplug, default = initial×2)

### Priority 2: Robustness
- [ ] FC thin pool + LAN connectivity verification
- [ ] Handle `ctr images mount` failure gracefully (disk full, network timeout)
- [ ] Containerd BoltDB recovery on stale locks
- [ ] ACPI shutdown: auto-force-stop after timeout (instead of waiting 90s)

### Priority 3: Future
- [ ] TLS/auth for flintlockd
- [ ] Update root README.md for GitHub
- [ ] Community Applications submission
- [ ] Multi-NIC support
- [ ] VM migration between hosts

## Development Notes

### Deploy Pattern
```bash
# Single file:
cat src/.../file | ssh -i ~/.ssh/mastervault root@192.168.50.6 'cat > /path'

# Full rebuild:
cd src && tar -czf ../plugin/microvms-2026.07.05.1.tgz usr/ && cd ..
```

### Testing Images
| Image | Type | Test Case |
|-------|------|-----------|
| docker.io/library/nginx:alpine | Web server | LAN access, ENTRYPOINT |
| docker.io/library/alpine:3.20 | Minimal | Shell-only, CMD |
| docker.io/library/redis:alpine | Service | Non-HTTP entrypoint |
| docker.io/library/httpd:alpine | Web server | Different entrypoint |

### SSH
```bash
ssh -i ~/.ssh/mastervault root@192.168.50.6
```
