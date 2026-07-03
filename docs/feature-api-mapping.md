# Feature → API Mapping

## Plugin UI Actions → Backend → Engine API

| UI Action | Backend cmd | Cloud Hypervisor API | Firecracker API | Status |
|-----------|-------------|---------------------|-----------------|--------|
| **Start** | `start` | `rc.microvm start_vm` → CLI boot | `rc.microvm start_vm` → `--config-file` | ✅ Working |
| **Stop** | `stop` | `ch-remote power-button` (ACPI) | `kill PID` (no ACPI) | ✅ Working |
| **Force Stop** | `force_stop` | `kill -9 PID` + rm socket | `kill -9 PID` + rm socket | ✅ Working |
| **Console** | `console` | `--serial pty` → ttyd on unix socket | ❌ Not supported (stdout only) | ✅ CH / ❌ FC |
| **Resize CPU** | `resize` | `ch-remote resize --cpus N` | ❌ Not supported | ✅ CH only |
| **Resize Memory** | `resize` | `ch-remote resize --memory BYTES` | ❌ Not supported (balloon only) | ✅ CH only |
| **Snapshot** | `snapshot` | `ch-remote pause` → `snapshot file://` → `resume` | `PATCH /vm {Paused}` → `PUT /snapshot/create` → `PATCH /vm {Resumed}` | ✅ CH / 🔧 FC (not impl) |
| **Restore** | `restore_snapshot` | `cloud-hypervisor --restore source_url=file://` | `PUT /snapshot/load` | ✅ CH / 🔧 FC (not impl) |
| **List Snapshots** | `list_snapshots` | PHP: glob VMDIR/name/snapshots/*/ | Same | ✅ Working |
| **Delete Snapshot** | `delete_snapshot` | PHP: rm -rf snapshot dir | Same | ✅ Working |
| **Info** | `info` | `ch-remote info` (JSON) | `GET /` via curl (InstanceInfo) | ✅ CH / ⚠️ FC (basic) |
| **Logs** | `logs_terminal` | ttyd + `tail -f vm.log` | Same | ✅ Working |
| **Remove** | `delete` | stop + rm -rf VMDIR/name | Same | ✅ Working |
| **Create** | `create` | PHP creates config.json + rootfs | Same | ✅ Working |
| **Autostart** | `autostart` | Updates config.json autostart field | Same | ✅ Working |

---

## Detailed API Call Mapping

### Start VM

**Cloud Hypervisor (CLI boot mode)**:
```bash
cloud-hypervisor \
  --api-socket /tmp/microvm-NAME.sock \
  --kernel VMDIR/kernels/cloud-hypervisor/vmlinux \
  --disk path=VMDIR/NAME/rootfs.raw \
  --cmdline "console=ttyS0 root=/dev/vda rw init=/init ip=IP::GW:MASK:::off" \
  --cpus boot=N,max=M \
  --memory size=XM,hotplug_size=YM \
  --net tap=tap-NAME,mac=MAC \
  --serial pty --console off \
  -v >> VMDIR/NAME/vm.log 2>&1 &
```

**Firecracker (config-file mode)**:
```bash
# Generate /tmp/microvm-NAME-fc.json:
{
  "boot-source": {"kernel_image_path": "...", "boot_args": "console=hvc0 ..."},
  "drives": [{"drive_id":"rootfs", "path_on_host":"...", "is_root_device":true, "is_read_only":false}],
  "network-interfaces": [{"iface_id":"eth0", "guest_mac":"...", "host_dev_name":"tap-NAME"}],
  "machine-config": {"vcpu_count": N, "mem_size_mib": M}
}
# Then:
firecracker --api-sock /tmp/microvm-NAME.sock --config-file /tmp/microvm-NAME-fc.json >> VMDIR/NAME/vm.log 2>&1 &
```

### Stop VM

**Cloud Hypervisor**:
```bash
ch-remote --api-socket /tmp/microvm-NAME.sock power-button
# Wait 30s, then force:
ch-remote --api-socket /tmp/microvm-NAME.sock shutdown-vmm
```

**Firecracker**:
```bash
kill PID
sleep 2
kill -0 PID && kill -9 PID  # force if still alive
rm -f /tmp/microvm-NAME.sock
```

### Resize (CH only)

```bash
# CPU
ch-remote --api-socket /tmp/microvm-NAME.sock resize --cpus 4
# Memory (bytes)
ch-remote --api-socket /tmp/microvm-NAME.sock resize --memory 1073741824
```

Backend also updates config.json with new values.

### Snapshot (CH only)

```bash
# Pause
ch-remote --api-socket /tmp/microvm-NAME.sock pause
# Snapshot to directory
ch-remote --api-socket /tmp/microvm-NAME.sock snapshot file:///VMDIR/NAME/snapshots/TAG
# Resume
ch-remote --api-socket /tmp/microvm-NAME.sock resume
```

### Restore (CH only)

```bash
# Must start fresh CH process with --restore flag
cloud-hypervisor --api-socket /tmp/microvm-NAME.sock \
  --restore source_url=file:///VMDIR/NAME/snapshots/TAG
```

### Console (CH only)

```bash
# PTY path discovered from vm.log:
# serial: SerialConfig { common: CommonConsoleConfig { file: Some("/dev/pts/X"), mode: Pty
#
# ttyd started on unix socket:
ttyd -d0 -W -i /var/tmp/microvm-NAME.console.sock /usr/local/bin/microvm-console /dev/pts/X
#
# Accessed via nginx proxy at: /logterminal/microvm-NAME.console/
```

### Info

**Cloud Hypervisor**:
```bash
ch-remote --api-socket /tmp/microvm-NAME.sock info
# Returns JSON: {"config":{...}, "state":"Running", "memory_actual_size":..., ...}
```

**Firecracker**:
```bash
curl --unix-socket /tmp/microvm-NAME.sock http://localhost/
# Returns: {"id":"anonymous-instance", "state":"Running", "vmm_version":"1.16.0", ...}
```

---

## Feature Support Matrix

| Feature | Cloud Hypervisor | Firecracker | Plugin Status |
|---------|:---:|:---:|:---:|
| Start/Stop | ✅ | ✅ | ✅ Done |
| Force Stop | ✅ | ✅ | ✅ Done |
| Serial Console | ✅ (PTY) | ❌ | ✅ CH only |
| Live CPU resize | ✅ | ❌ | ✅ CH only |
| Live RAM resize | ✅ | ❌ | ✅ CH only |
| Snapshot create | ✅ | ✅ | ✅ CH / 🔧 FC TODO |
| Snapshot restore | ✅ | ✅ | ✅ CH / 🔧 FC TODO |
| Disk hotplug | ✅ | ❌ | 📋 Planned |
| Net hotplug | ✅ | ❌ | 📋 Planned |
| Device remove | ✅ | ❌ | 📋 Planned |
| Live migration | ✅ | ❌ | 📋 Future |
| Balloon (mem) | ❌ | ✅ | 📋 Future |
| MMDS metadata | ❌ | ✅ | 📋 Future |
| VFIO/GPU | ✅ | ❌ | 📋 Future |
| Disk resize | ✅ | ❌ | 📋 Planned |
| Reboot | ✅ | ❌ | 📋 Planned |

---

## Context Menu Items per Engine

### Cloud Hypervisor (running)
1. Console → `cmd:console` → ttyd + PTY
2. Stop → `cmd:stop` → ch-remote power-button
3. Force Stop → `cmd:force_stop` → kill -9
4. Resize → `cmd:resize` → ch-remote resize
5. Snapshot → `cmd:snapshot` → pause+snap+resume
6. Snapshots → `cmd:list_snapshots` → swal dialog
7. Info → `cmd:info` → ch-remote info
8. Logs → `cmd:logs_terminal` → ttyd + tail
9. Remove → `cmd:delete` → stop + rm -rf

### Cloud Hypervisor (stopped)
1. Start → `cmd:start` → rc.microvm start_vm
2. Snapshots → list/restore
3. Info → show config.json
4. Logs → show vm.log
5. Remove → rm -rf

### Firecracker (running)
1. ~~Console~~ → "Not supported" error
2. Stop → `cmd:stop` → kill PID
3. Force Stop → `cmd:force_stop` → kill -9
4. Info → `cmd:info` → GET / via socket
5. Logs → `cmd:logs_terminal` → ttyd + tail
6. Remove → `cmd:delete` → kill + rm -rf

### Firecracker (stopped)
1. Start → `cmd:start` → rc.microvm start_vm
2. Info → show config.json
3. Logs → show vm.log
4. Remove → rm -rf

---

## TODO: Firecracker Snapshot Support

FC snapshots work differently than CH:
```bash
# 1. Pause
curl --unix-socket $SOCK -X PATCH http://localhost/vm -d '{"state":"Paused"}'
# 2. Snapshot
curl --unix-socket $SOCK -X PUT http://localhost/snapshot/create \
  -d '{"snapshot_type":"Full","snapshot_path":"/path/snap","mem_file_path":"/path/mem"}'
# 3. Resume
curl --unix-socket $SOCK -X PATCH http://localhost/vm -d '{"state":"Resumed"}'
# 4. Restore (new process)
firecracker --api-sock $SOCK &
curl --unix-socket $SOCK -X PUT http://localhost/snapshot/load \
  -d '{"snapshot_path":"/path/snap","mem_backend":{"backend_path":"/path/mem","backend_type":"File"}}'
```

Implementation needs:
- PHP `curl` to FC Unix socket (instead of ch-remote CLI)
- Different snapshot file structure (snap + mem vs CH directory)
- Add to context menu for FC VMs
