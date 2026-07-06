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


## Known Issues / Planned Fixes

### Thin Pool Create hangs on `ctr images pull`
- **Symptom**: Creating a VM with thin pool storage gets stuck at "Pulling image..." forever
- **Cause**: `ctr images pull` or `ctr images mount --snapshotter devmapper` can hang if:
  - containerd's devmapper snapshotter is in a bad state
  - The image is already pulled but the snapshot mount fails silently
  - Thin pool is full or has stale locks
- **Workaround**: Force stop the create, then manually `ctr -a /var/run/microvms/containerd.sock images ls` to check state
- **TODO**: Add timeout to the pull/mount step (e.g., 60s), show error if exceeded
- **TODO**: Pre-check if image already exists before pulling
- **TODO**: Add a "Cancel" button to the create progress UI



### Storage Tab: Prune button not working
- **Symptom**: "Clean dangling images" button shows "Done" but containerd says "No images pruned. `image prune` requires --all to be specified."
- **Cause**: `ctr images prune` without `--all` flag only removes dangling (untagged) images, and containerd's definition of "dangling" may differ from Docker's
- **Fix**: Add `--all` flag to the prune command, or provide separate buttons for "Prune unused" vs "Prune all"
- **Also**: The "Not valid!" error suggests a validation issue in the response handling



### IP address collision on create
- **Symptom**: Two VMs get assigned the same IP (192.168.50.220)
- **Cause**: IP allocation doesn't check existing VM configs for used IPs
- **Fix**: Scan all VM configs for assigned IPs before allocating a new one (or let user always specify manually)

### TAP interface not reusing lower numbers
- **Symptom**: New VMs get tap10, tap11, tap12 even when tap0-tap5 existed before
- **Cause**: TAP allocation fix now scans ALL system TAPs (including from libvirt/other services)
- **Fix**: Only count TAPs that are owned by microvms plugin (e.g., check if master is our bridge AND created by us). Or maintain a registry file of plugin-owned TAPs.



### Console: ^[[N;NR escape in prompt (cosmetic)
- **Status**: Existing VMs cosmetic only, self-resolves on recreate (TERM=dumb fix in new init)
- **No action needed**



### UI: Add VM Form Improvements
- **Autostart**: default to Yes (most VMs should autostart)
- **Networking section**: group IP Address + Gateway together, smarter form:
  - Auto-suggest next available IP from subnet
  - Gateway auto-fill from bridge config
  - Future: internal IPAM (IP Address Management) — allocate from pool, no manual entry
  - Future: remote IPAM integration (like Fly.io/AWS VPC pattern)
- **Field ordering**: more logical flow (Name → Image → Resources → Network → Options)
- **Rename "OCI/Docker Image"** → "Container Image" or "Image" (simpler, everyone knows what it means)
- **Reference**: Fly.io `fly machine run` — minimal required fields, smart defaults for everything else



### Firecracker Snapshots
- **Research**: FC supports memory+disk snapshots via API (`/snapshot/create`, `/snapshot/load`)
- **Previous work**: User has done this before — need to find and review past implementation
- **Use cases**: Fast restore (Lambda pattern), save/resume, clone VMs
- **API endpoints**:
  - `PUT /snapshot/create` — creates snapshot (mem + vmstate)
  - `PUT /snapshot/load` — restores from snapshot
  - `PATCH /vm` — resume after load
- **TODO**: Add Snapshot/Restore to FC context menu (like CH already has)
- **TODO**: Research if FC snapshot can be combined with our catatonit+fly-init pattern
- **Reference**: https://github.com/firecracker-microvm/firecracker/blob/main/docs/snapshotting/snapshot-support.md



### Storage Tab Fixes
- **Prune button**: Already fixed with `--all` flag (commit `d8a9b7b`) — verify it works now
- **Rename**: "Prune Unused Images" → "Prune Unused" (shorter, covers images + snapshots)
- **Image inventory**: Verify it shows images from both namespaces (cloud-hypervisor + firecracker)
- **Snapshot list**: Should show devmapper snapshots with their associated VMs
- **"Not valid!" error**: Fix response validation in the frontend after prune action

