# Next Session Plan

## ✅ COMPLETED

- Thin pool: `ctr images mount --snapshotter devmapper --rw` (full OCI flow)
- Init injection: OCI ENTRYPOINT/CMD via `crane config` + `escapeshellarg`
- CH disk: `readonly=false` (prevents sector 0 write block)
- Image ref normalization: `nginx:alpine` → `docker.io/library/nginx:alpine`
- FC binary: v1.16.1 (correct non-debug binary)
- VMM detection: by filename everywhere
- Add form: hide Disk Size/rootFS Source for Thin Pool
- Storage tab renamed from rootFS
- Separate namespaces per VMM (matches flintlock design)

## Testing Needed

1. **Create CH VM with Thin Pool** → verify nginx reachable on LAN
2. **Create FC VM with Thin Pool** → verify boots
3. **Create CH VM with Raw rootFS** → verify still works
4. **Delete thin pool VM** → verify snapshot cleanup

## Remaining

1. **Per-VM Logs button** — show /var/log/microvms/{vmm}/{name}.log
2. **TLS/auth for flintlockd** — deferred
3. **Storage tab** — "Prune Unused Images" button
4. **Update root README.md** — for GitHub
