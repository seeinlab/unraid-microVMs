# Components

## WebGUI Pages

| File | Menu | Purpose |
|------|------|---------|
| `MicroVMs.page` | Main tab container | Hosts sub-tabs |
| `MicroVMsMachines.page` | MicroVMs:1 | VM list, context menu (start/stop/snapshot/resize/delete) |
| `MicroVMsRootFS.page` | MicroVMs:2 | Storage management (title: "Storage") |
| `MicroVMsStats.page` | MicroVMs:3 | Usage statistics |
| `AddMicroVMs.page` | (popup) | Create VM form |
| `MicroVMsSettings.page` | OtherSettings (xmenu) | Settings container |
| `MicroVMsSettingsGeneral.page` | MicroVMsSettings:1 | Status tree, enable, storage, network, containerd |
| `MicroVMsSettingsCH.page` | MicroVMsSettings:2 | Cloud Hypervisor enable, kernel URL |
| `MicroVMsSettingsFC.page` | MicroVMsSettings:3 | Firecracker enable, kernel URL |
| `MicroVMsSettingsLM.page` | MicroVMsSettings:4 | Liquidmetal: flintlockd, registry |

## Backend (PHP)

### MicroVMAdmin.php
AJAX command handler. Receives `$_REQUEST['cmd']` and dispatches:

| Command | Action |
|---------|--------|
| `create` | Create VM (thin pool or raw rootFS) |
| `start` / `stop` / `force_stop` | VM lifecycle |
| `delete` | Delete VM + rootFS/snapshot |
| `resize` | Hot-add CPU/RAM (CH only) |
| `snapshot` / `list_snapshots` / `restore_snapshot` / `delete_snapshot` | CH snapshots |
| `info` / `status` | VM information |
| `view_log` | Read service log (by service name) |
| `service_action` | Start/stop/restart containerd/flintlockd/registry |
| `toggle_setting` | Enable/disable VMM/devmapper/liquidmetal |
| `download_kernel` | Download CH/FC kernel |

### common.php
Shared functions:

| Function | Purpose |
|----------|---------|
| `microvm_load_config()` | Parse controlplane.cfg |
| `microvm_list_vms()` | Scan VM dirs, return config + state |
| `microvm_find_config_file($path)` | Find cloud-hypervisor.json or firecracker.json |
| `microvm_get_vmm($pathOrConfig)` | Determine VMM from filename |
| `microvm_get_network($config)` | Extract network section |
| `microvm_next_tap_id($vmdir)` | Find lowest available TAP ID |
| `microvm_log($msg)` | Log to backend.log |

## Service Script (rc.microvms)

### Lifecycle Commands
| Command | Function |
|---------|----------|
| `start` | Full boot sequence (7 steps) |
| `stop` | Graceful shutdown (6 steps) |
| `restart` | Stop + start |
| `start_vm {name}` | Start single VM |
| `stop_vm {name}` | Stop single VM (ACPI + force) |
| `start_containerd` | Start microvms-containerd |
| `stop_containerd` | Stop microvms-containerd |
| `start_flintlockd` | Start flintlockd gRPC |
| `stop_flintlockd` | Stop flintlockd |
| `start_registry` | Start crane OCI registry |
| `stop_registry` | Stop crane registry |
| `create_thin_rootfs` | Create thin device via ctr |
| `delete_thin_rootfs` | Remove thin snapshot |
| `activate_thin_rootfs` | Get device path for VM start |
| `setup_thinpool` | Create loop-backed thin pool |
| `teardown_thinpool` | Remove thin pool |

## Event Hooks

| File | Trigger | Action |
|------|---------|--------|
| `event/array_started` | Array comes online | Start rc.microvms if SERVICE=enable |
| `event/stopping_svcs` | Array stopping | Stop rc.microvms |

## Plugin Installer (microvms.plg)

XML plugin definition. On install:
1. Downloads binaries (CH, FC, containerd, crane, ttyd, grpcurl, flintlockd)
2. Extracts tarballs (FC, containerd, crane, grpcurl)
3. Installs tgz (emhttp pages + rc script)
4. Creates symlinks, config defaults
5. Does NOT start services (deferred to array_started event)
