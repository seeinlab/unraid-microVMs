<?php
/*
 * microVMs for Unraid
 * Copyright (C) 2026
 * License: GPL-2.0
 *
 * File: backend/MicroVMAdmin.php
 * Description: AJAX command handler for all microVM operations.
 *              Receives POST requests from WebGUI JavaScript and dispatches
 *              to appropriate functions in common.php.
 *
 * Commands (POST 'cmd' parameter):
 *   Lifecycle: list, start, stop, force_stop, status
 *   Info:      info, logs, logs_terminal
 *   Resize:    resize (Cloud Hypervisor only)
 *   Snapshots: snapshot, list_snapshots, delete_snapshot, restore_snapshot
 *   Console:   console, console_stop
 *   CRUD:      create, create_json, delete, delete_rootfs, pull_rootfs
 *   Config:    autostart, service
 *
 * Mode: Direct (CH/FC via rc.microvms). Flintlock services available for remote automation.
 *
 * References:
 *   - docs/feature-api-mapping.md (UI ΓåÆ Backend ΓåÆ Engine API)
 *   - docs/cloud-hypervisor-api.md
 *   - docs/firecracker-api.md
 */
error_reporting(0); // Suppress warnings from mixing with JSON output

$plugin = "microvms";
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once "$docroot/plugins/$plugin/include/common.php";

header('Content-Type: application/json');

// Log function
function microvm_log($msg) {
    $logfile = '/var/log/microvms/backend.log';
    $ts = date('Y-m-d H:i:s');
    file_put_contents($logfile, "[$ts] $msg\n", FILE_APPEND);
}

$cfg = microvm_load_config();
$vmdir = $cfg['VMDIR'] ?? '/mnt/user/microvms';
$bridge = $cfg['BRIDGE'] ?? 'br0';

$cmd = $_REQUEST['cmd'] ?? '';
$name = $_REQUEST['name'] ?? '';

switch ($cmd) {
    case 'list':
        $vms = microvm_list_vms();
        echo json_encode($vms);
        break;

    case 'start':
        $result = microvm_start_vm($name);
        echo json_encode($result);
        break;

    case 'stop':
        $result = microvm_stop_vm($name);
        echo json_encode($result);
        break;

    case 'force_stop':
        $sock = "/tmp/microvms-{$name}.sock";
        // Kill any ttyd console relay
        $ttydPid = "/tmp/ttyd-microvms-{$name}.pid";
        if (file_exists($ttydPid)) {
            $tpid = trim(file_get_contents($ttydPid));
            if ($tpid) exec("kill $tpid 2>/dev/null");
            @unlink($ttydPid);
        }
        // Find the VM's PID via pidof + cmdline (reliable, no pgrep false positives)
        $pid = '';
        $allPids = trim(shell_exec("pidof cloud-hypervisor 2>/dev/null")) . ' ' . trim(shell_exec("pidof firecracker 2>/dev/null"));
        foreach (explode(' ', trim($allPids)) as $p) {
            if (empty($p)) continue;
            $cmdline = @file_get_contents("/proc/$p/cmdline");
            if ($cmdline && strpos($cmdline, "microvms-{$name}.sock") !== false) {
                $pid = $p;
                break;
            }
        }
        if ($pid) {
            exec("kill -9 $pid 2>&1");
            sleep(1);
            @unlink($sock);
            echo json_encode(['success' => true, 'message' => "Force killed VM $name (PID: $pid)"]);
        } else {
            @unlink($sock);
            echo json_encode(['success' => true, 'message' => "No process found, cleaned stale socket"]);
        }
        break;

    case 'vm_config':
        // Return VM config JSON (for Edit form — always from file, not live state)
        $configFile = microvm_find_config_file("$vmdir/$name");
        if ($configFile) {
            echo file_get_contents($configFile);
        } else {
            echo json_encode(['error' => 'Config not found']);
        }
        break;

    case 'info':
        $info = microvm_get_vm_info($name);
        if ($info) {
            echo json_encode($info);
        } else {
            $configFile = microvm_find_config_file("$vmdir/$name");
            if ($configFile) {
                echo file_get_contents($configFile);
            } else {
                echo json_encode(['error' => 'VM not found']);
            }
        }
        break;

    case 'status':
        $sock = "/tmp/microvms-{$name}.sock";
        $running = false;
        if (file_exists($sock)) {
            exec("ch-remote --api-socket $sock ping 2>/dev/null", $output, $ret);
            $running = ($ret === 0);
        }
        echo json_encode([
            'success' => true,
            'name' => $name,
            'state' => $running ? 'running' : 'stopped',
        ]);
        break;

    case 'resize':
        // Only Cloud Hypervisor supports live resize via ch-remote
        $configFile = microvm_find_config_file("$vmdir/$name");
        $vmConfig = [];
        if ($configFile) {
            $vmConfig = json_decode(file_get_contents($configFile), true);
            $vmEngine = microvm_get_vmm($vmConfig);
            if ($vmEngine === 'firecracker') {
                echo json_encode(['success' => false, 'error' => 'Live resize not supported for Firecracker VMM. Recreate VM with new config.']);
                break;
            }
        }
        $cpus = $_POST['cpus'] ?? null;
        $memory = $_POST['memory'] ?? null;
        $result = microvm_resize_vm($name, $cpus, $memory);
        // Update config with new values if resize succeeded
        if (!empty($result['cpus']) && $cpus) {
            if (isset($vmConfig['vcpus'])) {
                $vmConfig['vcpus'] = intval($cpus);
            } else {
                $vmConfig['boot_vcpus'] = intval($cpus);
            }
            // Ensure max_vcpus exists (legacy configs may not have it)
            if (!isset($vmConfig['max_vcpus'])) {
                $vmConfig['max_vcpus'] = max(intval($cpus) * 2, 4);
            }
        }
        if (!empty($result['memory']) && $memory) {
            $vmConfig['memory_mb'] = intval($memory) / 1048576; // bytes to MB
        }
        if (!empty($vmConfig) && $configFile) {
            file_put_contents($configFile, json_encode($vmConfig, JSON_PRETTY_PRINT));
        }
        echo json_encode($result);
        break;

    case 'snapshot':
        $tag = $_POST['tag'] ?? null;
        $result = microvm_snapshot_vm($name, $tag);
        echo json_encode($result);
        break;

    case 'list_snapshots':
        $snapshots = microvm_list_snapshots($name);
        echo json_encode(['success' => true, 'snapshots' => $snapshots]);
        break;

    case 'delete_snapshot':
        $tag = $_POST['tag'] ?? '';
        if (empty($tag)) {
            echo json_encode(['success' => false, 'error' => 'No snapshot tag specified']);
            break;
        }
        $result = microvm_delete_snapshot($name, $tag);
        microvm_log("DELETE_SNAPSHOT: $name/$tag - " . ($result['success'] ? 'OK' : $result['error'] ?? 'FAIL'));
        echo json_encode($result);
        break;

    case 'restore_snapshot':
        $tag = $_POST['tag'] ?? '';
        if (empty($tag)) {
            echo json_encode(['success' => false, 'error' => 'No snapshot tag specified']);
            break;
        }
        microvm_log("RESTORE_SNAPSHOT: $name from $tag");
        $result = microvm_restore_snapshot($name, $tag);
        microvm_log("RESTORE_RESULT: " . json_encode($result));
        echo json_encode($result);
        break;

    case 'delete':
        $vmPath = "$vmdir/$name";

        // Check for snapshots - block delete if any exist
        $snapDir = "$vmPath/snapshots";
        if (is_dir($snapDir) && count(glob("$snapDir/*")) > 0) {
            $snapCount = count(glob("$snapDir/*"));
            echo json_encode(['success' => false, 'error' => "Cannot delete: VM has $snapCount snapshot(s). Remove snapshots first."]);
            break;
        }
        // Stop VM if running
        microvm_stop_vm($name);
        sleep(2);
        // Force kill if still running (use pidof + cmdline)
        $allPids = trim(shell_exec("pidof cloud-hypervisor 2>/dev/null")) . ' ' . trim(shell_exec("pidof firecracker 2>/dev/null"));
        foreach (explode(' ', trim($allPids)) as $p) {
            if (empty($p)) continue;
            $cmdline = @file_get_contents("/proc/$p/cmdline");
            if ($cmdline && strpos($cmdline, "microvms-{$name}.sock") !== false) {
                exec("kill -9 $p 2>/dev/null");
                break;
            }
        }
        @unlink("/tmp/microvms-{$name}.sock");

        // Delete thin device if VM uses one
        $configFile = microvm_find_config_file($vmPath);
        if ($configFile) {
            $vmConfig = json_decode(file_get_contents($configFile), true);
            $storage = microvm_get_storage($vmConfig);
            $thinId = $storage['thin_device_id'] ?? null;
            if ($thinId) {
                exec("/etc/rc.d/rc.microvmss delete_thin_rootfs " . escapeshellarg($name) . " $thinId 2>&1");
            }
        }

        // Remove from containerd registry
        $ctrSock = '/var/run/microvms/containerd.sock';
        exec("ctr -a $ctrSock -n default containers rm " . escapeshellarg($name) . " 2>/dev/null");
        // Remove state directory
        exec("rm -rf /var/run/microvms/*/". escapeshellarg($name) . " 2>/dev/null");

        // Remove entire VM folder (config + rootfs)
        if (is_dir($vmPath)) {
            exec("rm -rf " . escapeshellarg($vmPath) . " 2>&1", $output, $ret);
            // On Unraid user shares, .fuse_hidden files may linger — treat as success
            $remaining = is_dir($vmPath) ? glob("$vmPath/*") : [];
            $deleted = ($ret === 0) || empty($remaining);
            echo json_encode(['success' => $deleted, 'message' => "VM '$name' deleted."]);
        } else {
            echo json_encode(['success' => false, 'error' => 'VM folder not found']);
        }
        break;

    case 'create':
        microvm_log("CREATE: " . json_encode($_POST));
        $name = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['name'] ?? ''));
        if (empty($name)) {
            echo json_encode(['success' => false, 'error' => 'Invalid name']);
            break;
        }

        $cpus = intval($_POST['cpus'] ?? $cfg['DEFAULT_CPUS'] ?? 1);
        $memory = intval($_POST['memory'] ?? $cfg['DEFAULT_MEMORY'] ?? 256);
        $max_memory = intval($_POST['max_memory'] ?? 0);
        if ($max_memory <= 0 || $max_memory < $memory) {
            $max_memory = $memory * 2;
        }
        $ip = $_POST['ip'] ?? '';
        $gateway = $_POST['gateway'] ?? '192.168.50.1';
        $source = $_POST['source'] ?? 'oci';
        $ociImage = $_POST['oci_image'] ?? 'nginx:alpine';
        $diskSize = intval($_POST['disk_size'] ?? 500);
        $rootfsPath = $_POST['rootfs_path'] ?? '';
        $vmm = $_REQUEST['engine'] ?? 'cloud-hypervisor';

        // --- Direct Mode ---
        $vmPath = "$vmdir/$name";
        $systemDir = '/mnt/user/system/microvms';

        // Create VM directory
        @mkdir($vmPath, 0755, true);

        // Generate MAC
        $mac = sprintf("52:54:00:%02x:%02x:%02x", rand(0,255), rand(0,255), rand(0,255));

        // Storage type
        // ────────────────────────────────────────────────────────────────────────
        // Storage modes:
        //   Raw:  dd creates sparse file → mkfs.ext4 → mount file → extract tar
        //         → umount → VM uses file directly (disk = $vmdir/rootfs.raw)
        //         Resolved via resolve_path() to avoid FUSE overhead.
        //
        //   Thin: containerd devmapper snapshotter creates thin-provisioned block
        //         device → mkfs.ext4 on /dev/mapper/... → mount device → extract
        //         tar → umount → VM uses block device path directly.
        //         At VM start: disk = activate_thin_rootfs($name, $vmm) → /dev/mapper/...
        //
        // Config JSON stores: storage.type = 'thin' | 'raw', storage.size_mb = N
        // ────────────────────────────────────────────────────────────────────────
        $storageType = $_POST['storage_type'] ?? 'thin';
        $thinDeviceId = null; // containerd manages device IDs

        // Create rootfs
        if ($source === 'oci' && !empty($ociImage)) {

            if ($storageType === 'thin') {
                // Thin Pool: containerd pulls image → devmapper snapshot → mount → inject init
                $sock = '/var/run/microvms/containerd.sock';
                $snapshotKey = "vm-$name";
                $ctr = "ctr -a $sock -n default";

                // Normalize image reference for containerd (requires fully qualified)
                $pullImage = $ociImage;
                // Add docker.io/ prefix if no registry specified
                if (!str_contains($pullImage, '/')) {
                    $pullImage = "docker.io/library/$pullImage";
                } elseif (preg_match('#^docker\.io/([^/]+)$#', $pullImage, $m)) {
                    // docker.io/nginx → docker.io/library/nginx
                    $pullImage = "docker.io/library/" . $m[1];
                } elseif (!str_contains($pullImage, '.') && substr_count($pullImage, '/') === 1) {
                    // nginx:alpine or ubuntu:22.04 (no dots, single slash = Docker Hub)
                    $pullImage = "docker.io/library/$pullImage";
                }
                // Add :latest if no tag
                if (!str_contains($pullImage, ':')) $pullImage .= ':latest';

                // 1. Pull image into containerd
                microvm_log("Pulling OCI via containerd: $pullImage");
                exec("$ctr images pull --platform linux/amd64 " . escapeshellarg($pullImage) . " 2>&1", $pullOutput, $pullRet);
                microvm_log("ctr pull exit: $pullRet");
                if ($pullRet !== 0) {
                    echo json_encode(['success' => false, 'error' => 'Failed to pull image: ' . implode("\n", $pullOutput)]);
                    break;
                }

                // 2. Mount image as writable devmapper snapshot (handles unpack automatically)
                $mountPoint = "/tmp/microvm-mount-$name";
                exec("mkdir -p $mountPoint");
                // Remove stale snapshot if exists (re-create)
                exec("$ctr images unmount " . escapeshellarg($mountPoint) . " 2>/dev/null");
                exec("$ctr snapshots --snapshotter devmapper remove " . escapeshellarg($mountPoint) . " 2>/dev/null");

                exec("$ctr images mount --snapshotter devmapper --rw --platform linux/amd64 " . escapeshellarg($pullImage) . " " . escapeshellarg($mountPoint) . " 2>&1", $mountOut, $mountRet);
                if ($mountRet !== 0) {
                    echo json_encode(['success' => false, 'error' => 'Failed to mount image to devmapper: ' . implode("\n", $mountOut)]);
                    break;
                }

                // 3. Get device path from mount
                $rootfs = trim(shell_exec("mount | grep " . escapeshellarg($mountPoint) . " | awk '{print \$1}'"));
                if (empty($rootfs)) {
                    echo json_encode(['success' => false, 'error' => 'Cannot determine device path after mount']);
                    exec("$ctr images unmount " . escapeshellarg($mountPoint) . " 2>/dev/null");
                    break;
                }
            } else {
                // Raw rootFS: crane export → dd → mkfs → mount → extract tar → inject init
                microvm_log("Pulling OCI via crane: $ociImage");
                $tmpTar = "/tmp/microvm-$name.tar";
                exec("crane export " . escapeshellarg($ociImage) . " " . escapeshellarg($tmpTar) . " 2>&1", $pullOutput, $pullRet);
                microvm_log("crane exit: $pullRet, output: " . implode(" ", $pullOutput));
                if ($pullRet !== 0) {
                    echo json_encode(['success' => false, 'error' => 'Failed to pull image: ' . implode("\n", $pullOutput)]);
                    break;
                }

                // Raw: dd creates sparse file → mkfs.ext4 → mount+inject in single script
                $rootfs = "$vmPath/rootfs.raw";
                exec("dd if=/dev/zero of=$rootfs bs=1M count=$diskSize 2>/dev/null");
                exec("mkfs.ext4 -F $rootfs 2>/dev/null");
                $mountDev = $rootfs;
                foreach (["/mnt/cache", "/mnt/mtier", "/mnt/ztier", "/mnt/rtier"] as $base) {
                    $candidate = $base . "/" . ltrim(str_replace("/mnt/user/", "", $rootfs), "/");
                    if (file_exists($candidate)) { $mountDev = $candidate; break; }
                }
            }

            // Get OCI image entrypoint and cmd
            $entrypoint = '';
            $cmd = '';
            if (!empty($ociImage)) {
                $imageConfig = shell_exec("crane config " . escapeshellarg($ociImage) . " 2>/dev/null");
                if ($imageConfig) {
                    $imgCfg = json_decode($imageConfig, true);
                    $ep = $imgCfg['config']['Entrypoint'] ?? [];
                    $cm = $imgCfg['config']['Cmd'] ?? [];
                    $entrypoint = implode(' ', array_map('escapeshellarg', $ep));
                    $cmd = implode(' ', array_map('escapeshellarg', $cm));
                }
            }
            $execCmd = trim("$entrypoint $cmd");
            if (empty($execCmd)) $execCmd = '/bin/sh';

            // Generate /fly/run.json content
            $enableConsole = ($_REQUEST['console'] ?? 'true') === 'true';
            $runConfig = [
                'entrypoint' => $entrypoint ? array_map('trim', explode("' '", trim($entrypoint, "'"))) : [],
                'cmd' => $cmd ? array_map('trim', explode("' '", trim($cmd, "'"))) : [],
                'hostname' => $name,
                'network' => [
                    'ip' => $_REQUEST['ip'] ?? '',
                    'gateway' => $_REQUEST['gateway'] ?? '',
                    'mask' => '255.255.255.0',
                    'dns' => ['8.8.8.8', '1.1.1.1'],
                ],
                'console' => $enableConsole,
                'tty' => true,
            ];
            $runJson = json_encode($runConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            // Single script: inject init files into already-mounted rootfs
            // For thin pool: containerd already mounted at $mountPath
            // For raw: script handles mount → extract → inject → unmount
            $mountPath = "/tmp/microvm-mount-$name";
            $tarCmd = ($storageType !== 'thin' && isset($tmpTar)) ? "tar -xf $tmpTar -C $mountPath" : "true";
            $umountCmd = ($storageType === 'thin') ? "$ctr images unmount $mountPath 2>/dev/null" : "umount $mountPath";
            $mountCmd = ($storageType === 'thin') ? "true" : "mount $mountDev \$MOUNT";
            $precleanCmd = ($storageType === 'thin') ? "true" : "umount \$MOUNT 2>/dev/null || true";

            $createScript = <<<SCRIPT
#!/bin/bash
set -e
MOUNT="$mountPath"

# Cleanup stale mount from previous failed attempt (raw only)
$precleanCmd
mkdir -p \$MOUNT

# Cleanup function on failure
cleanup() { $umountCmd; rmdir \$MOUNT 2>/dev/null; }
trap cleanup EXIT

$mountCmd
$tarCmd
mkdir -p \$MOUNT/fly \$MOUNT/sbin
cp /usr/local/share/microvms/fly-init \$MOUNT/fly/init
chmod 755 \$MOUNT/fly/init
cp /usr/local/share/microvms/catatonit \$MOUNT/sbin/catatonit
chmod 755 \$MOUNT/sbin/catatonit
ln -sf /fly/init \$MOUNT/init
cat > \$MOUNT/fly/run.json << 'RUNJSONEOF'
$runJson
RUNJSONEOF
$umountCmd
trap - EXIT
rmdir \$MOUNT 2>/dev/null
SCRIPT;
            $scriptPath = "/tmp/microvm-create-$name.sh";
            file_put_contents($scriptPath, $createScript);
            chmod($scriptPath, 0755);
            exec("$scriptPath 2>&1", $scriptOut, $scriptRet);
            @unlink($scriptPath);
            if ($scriptRet !== 0) {
                microvm_log("Create script FAILED (ret=$scriptRet): " . implode("\n", $scriptOut));
                echo json_encode(['success' => false, 'error' => 'rootfs injection failed: ' . implode(' ', $scriptOut)]);
                break;
            }
            microvm_log("rootfs created and injected OK");
            if ($storageType !== 'thin' && isset($tmpTar)) @unlink($tmpTar);

        } elseif ($source === 'existing' && !empty($rootfsPath)) {
            // Existing rootfs — no storage creation needed
        }

        // Build new config format
        $storageConfig = ['type' => $storageType];
        if ($storageType === 'thin') {
            $storageConfig['image_ref'] = $ociImage;
        } else {
            $storageConfig['size_mb'] = $diskSize;
        }

        $config = [
            'name' => $name,
            'namespace' => 'default',
            'vcpus' => $cpus,
            'max_vcpus' => intval($_REQUEST['max_cpus'] ?? ($cpus * 2)),
            'memory_mb' => $memory,
            'max_memory_mb' => $max_memory,
            'storage' => $storageConfig,
            'network' => [
                'ip' => $ip,
                'gateway' => $gateway,
                'mac' => $mac,
                'bridge' => $bridge,
                'tap_id' => microvm_next_tap_id($vmdir),
            ],
            'image' => [
                'source' => $source,
                'ref' => ($source === 'oci') ? $ociImage : ($rootfsPath ?: ''),
            ],
            'kernel' => [
                'cmdline' => 'console=ttyS0 root=/dev/vda rw init=/fly/init',
            ],
            'autostart' => (($_POST['autostart'] ?? 'false') === 'true'),
            'console' => $enableConsole,
        ];
        if (!empty($thinDeviceId)) {
            $config['storage']['thin_device_id'] = $thinDeviceId;
        }

        // Write config as {vmm}.json
        $configFilename = "$vmPath/$vmm.json";
        file_put_contents($configFilename, json_encode($config, JSON_PRETTY_PRINT));

        microvm_log("VM created: $name at $vmPath (config: $vmm.json)");
        // Auto-start the VM if autostart is enabled
        if (($_POST['autostart'] ?? 'false') === 'true') {
            $startResult = microvm_start_vm($name);
            if (!empty($startResult['success'])) {
                echo json_encode(['success' => true, 'message' => "VM '$name' created and started.", 'started' => true, 'output' => $startResult['output'] ?? '']);
            } else {
                echo json_encode(['success' => true, 'message' => "VM '$name' created but failed to start: " . ($startResult['error'] ?? 'unknown'), 'started' => false, 'output' => $startResult['output'] ?? '']);
            }
        } else {
            echo json_encode(['success' => true, 'message' => "VM '$name' created. Turn on Autostart to auto-boot after create."]);
        }
        break;

    case 'create_json':
        $json = $_POST['config'] ?? '';
        $config = json_decode($json, true);
        if (!$config || empty($config['name'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid JSON or missing name']);
            break;
        }
        $name = preg_replace('/[^a-z0-9\-]/', '', strtolower($config['name']));
        $vmm = $config['vmm'] ?? $config['engine'] ?? 'cloud-hypervisor';
        $vmPath = "$vmdir/$name";
        @mkdir($vmPath, 0755, true);
        // Write as {vmm}.json
        $configFilename = "$vmPath/$vmm.json";
        file_put_contents($configFilename, json_encode($config, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'message' => "VM '$name' created from JSON."]);
        break;

    case 'console':
        // Serial console via ttyd + unix socket (proxied by Unraid nginx at /logterminal/)
        $logFile = "$vmdir/$name/vm.log";
        if (!file_exists($logFile)) $logFile = microvm_get_log_path($name, $vmdir);
        $sock = "/tmp/microvms-{$name}.sock";

        // Check VM is running
        if (!file_exists($sock)) {
            echo json_encode(['success' => false, 'error' => "VM '$name' is not running"]);
            break;
        }

        // Detect engine
        $configFile = microvm_find_config_file("$vmdir/$name");
        $vmConfig = $configFile ? json_decode(file_get_contents($configFile), true) : [];
        $engine = microvm_get_vmm($configFile);

        if ($engine === 'firecracker') {
            // FC: uses FIFO for input, log for output
            $fifoFile = "/var/tmp/microvms-{$name}.fifo";
            $fifoPath = file_exists($fifoFile) ? trim(file_get_contents($fifoFile)) : "/tmp/microvms-{$name}.fifo";
            if (!file_exists($fifoPath)) {
                echo json_encode(['success' => false, 'error' => "No console FIFO available. Restart the VM to enable console."]);
                break;
            }

            // Check ttyd
            $ttydBin = '/usr/local/bin/ttyd';
            if (!is_executable($ttydBin)) {
                echo json_encode(['success' => false, 'error' => 'ttyd not installed.']);
                break;
            }

            $sockName = "microvm-{$name}.console";
            $sockPath = "/var/tmp/{$sockName}.sock";
            $pidFile = "/var/tmp/ttyd-microvms-{$name}.pid";

            // Kill existing ttyd
            if (file_exists($pidFile)) {
                $oldPid = trim(file_get_contents($pidFile));
                if ($oldPid && file_exists("/proc/$oldPid")) {
                    exec("kill $oldPid 2>/dev/null");
                    usleep(500000);
                }
                @unlink($pidFile);
            }
            @unlink($sockPath);

            $cmd = sprintf(
                'nohup %s -d0 -W -t rendererType=canvas -t closeOnDisconnect=true -t disableLeaveAlert=true ' .
                "-t 'theme={\"background\":\"black\"}' -t fontSize=15 -t fontFamily=monospace " .
                '-i %s /usr/local/bin/microvms-console-fc %s > /dev/null 2>&1 & echo $!',
                $ttydBin,
                escapeshellarg($sockPath),
                escapeshellarg($name)
            );
            $pid = trim(shell_exec($cmd));
            if ($pid) {
                file_put_contents($pidFile, $pid);
            }
            usleep(500000);

            if (file_exists($sockPath)) {
                echo json_encode([
                    'success' => true,
                    'url' => "/logterminal/{$sockName}/",
                    'name' => $name,
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => "Failed to start console (ttyd socket not created)"]);
            }
            break;
        } elseif ($engine !== 'cloud-hypervisor') {
            echo json_encode(['success' => false, 'error' => "Console not supported for this VMM."]);
            break;
        } else {
            // Cloud Hypervisor: use FIFO approach (same as FC)
            $fifoPath = "/tmp/microvms-{$name}.fifo";
            if (!file_exists($fifoPath)) {
                echo json_encode(['success' => false, 'error' => "No console FIFO available. Restart the VM to enable console."]);
                break;
            }

            // Check ttyd
            $ttydBin = '/usr/local/bin/ttyd';
            if (!is_executable($ttydBin)) {
                echo json_encode(['success' => false, 'error' => 'ttyd not installed.']);
                break;
            }

            $sockName = "microvm-{$name}.console";
            $sockPath = "/var/tmp/{$sockName}.sock";
            $pidFile = "/var/tmp/ttyd-microvms-{$name}.pid";

            // Kill existing ttyd
            if (file_exists($pidFile)) {
                $oldPid = trim(file_get_contents($pidFile));
                if ($oldPid && file_exists("/proc/$oldPid")) {
                    exec("kill $oldPid 2>/dev/null");
                    usleep(500000);
                }
                @unlink($pidFile);
            }
            @unlink($sockPath);

            $cmd = sprintf(
                'nohup %s -d0 -W -t rendererType=canvas -t closeOnDisconnect=true -t disableLeaveAlert=true ' .
                "-t 'theme={\"background\":\"black\"}' -t fontSize=15 -t fontFamily=monospace " .
                '-i %s /usr/local/bin/microvms-console-fc %s > /dev/null 2>&1 & echo $!',
                $ttydBin,
                escapeshellarg($sockPath),
                escapeshellarg($name)
            );
            $pid = trim(shell_exec($cmd));
            if ($pid) {
                file_put_contents($pidFile, $pid);
            }
            usleep(500000);

            if (file_exists($sockPath)) {
                echo json_encode([
                    'success' => true,
                    'url' => "/logterminal/{$sockName}/",
                    'name' => $name,
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => "Failed to start console (ttyd socket not created)"]);
            }
            break;
        }
        break;

    case 'console_stop':
        // Stop ttyd relay for a VM
        $pidFile = "/tmp/ttyd-microvms-{$name}.pid";
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if ($pid && file_exists("/proc/$pid")) {
                exec("kill $pid 2>/dev/null");
            }
            @unlink($pidFile);
            echo json_encode(['success' => true, 'message' => "Console relay stopped for $name"]);
        } else {
            echo json_encode(['success' => true, 'message' => "No active console relay for $name"]);
        }
        break;

    case 'logs':
        // Return last 100 lines of VM log (AJAX)
        $logFile = "$vmdir/$name/vm.log";
        if (!file_exists($logFile)) $logFile = microvm_get_log_path($name, $vmdir);
        if (file_exists($logFile)) {
            $lines = shell_exec("tail -100 " . escapeshellarg($logFile) . " 2>/dev/null");
            echo json_encode(['success' => true, 'log' => $lines]);
        } else {
            echo json_encode(['success' => false, 'error' => "No log file found for '$name'"]);
        }
        break;

    case 'logs_terminal':
        // Open log viewer via ttyd + unix socket (proxied at /logterminal/)
        $logFile = "$vmdir/$name/vm.log";
        // Fallback to old path if new path doesn't exist
        if (!file_exists($logFile)) {
            $logFile = microvm_get_log_path($name, $vmdir);
        }
        if (!file_exists($logFile)) {
            echo json_encode(['success' => false, 'error' => "No log file found for '$name'"]);
            break;
        }

        $ttydBin = '/usr/local/bin/ttyd';
        if (!is_executable($ttydBin)) {
            echo json_encode(['success' => false, 'error' => 'ttyd not installed']);
            break;
        }

        $sockName = "microvm-{$name}.log";
        $sockPath = "/var/tmp/{$sockName}.sock";
        $pidFile = "/var/tmp/ttyd-microvms-{$name}-log.pid";

        // Kill existing
        if (file_exists($pidFile)) {
            $oldPid = trim(file_get_contents($pidFile));
            if ($oldPid && file_exists("/proc/$oldPid")) {
                exec("kill $oldPid 2>/dev/null");
                usleep(300000);
            }
            @unlink($pidFile);
        }
        @unlink($sockPath);

        // Start ttyd with tail -f on the log file
        $cmd = sprintf(
            'nohup %s -d0 -W -t rendererType=canvas -t closeOnDisconnect=true -t disableLeaveAlert=true ' .
            "-t 'theme={\"background\":\"black\"}' -t fontSize=15 -t fontFamily=monospace " .
            '-i %s tail -f -n 90 %s > /dev/null 2>&1 & echo $!',
            $ttydBin,
            escapeshellarg($sockPath),
            escapeshellarg($logFile)
        );
        $pid = trim(shell_exec($cmd));
        if ($pid) file_put_contents($pidFile, $pid);

        usleep(500000);

        if (file_exists($sockPath)) {
            echo json_encode(['success' => true, 'url' => "/logterminal/{$sockName}/"]);
        } else {
            echo json_encode(['success' => false, 'error' => "Failed to start log viewer"]);
        }
        break;

    case 'autostart':
        // Toggle autostart for a VM
        $enabled = ($_POST['enabled'] ?? 'false') === 'true';
        $configFile = microvm_find_config_file("$vmdir/$name");
        if ($configFile) {
            $vmConfig = json_decode(file_get_contents($configFile), true);
            $vmConfig['autostart'] = $enabled;
            file_put_contents($configFile, json_encode($vmConfig, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true, 'autostart' => $enabled]);
        } else {
            echo json_encode(['success' => false, 'error' => 'VM config not found']);
        }
        break;

    case 'service':
        $action = $_POST['action'] ?? '';
        if (in_array($action, ['start', 'stop', 'restart'])) {
            exec("/etc/rc.d/rc.microvmss $action 2>&1", $output, $ret);
            echo json_encode(['success' => ($ret === 0), 'message' => implode("\n", $output)]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
        break;

    case 'liquidmetal':
        // Toggle Liquidmetal services (flintlockd + containerd + registry) for remote automation
        $action = $_POST['action'] ?? '';
        if ($action === 'status') {
            $running = microvm_is_flintlock_running();
            $containerdPid = trim(shell_exec("cat /var/run/microvms/containerd.pid 2>/dev/null"));
            $registryPid = trim(shell_exec("cat /var/run/microvms/crane-registry.pid 2>/dev/null"));
            echo json_encode([
                'success' => true,
                'running' => $running,
                'services' => [
                    'flintlockd' => !empty(trim(shell_exec("pgrep -x flintlockd 2>/dev/null"))),
                    'containerd' => !empty($containerdPid) && file_exists("/proc/$containerdPid"),
                    'registry' => !empty($registryPid) && file_exists("/proc/$registryPid"),
                ],
                'grpc_port' => 9090,
            ]);
        } elseif ($action === 'start') {
            // Start containerd, registry, flintlockd
            exec("/etc/rc.d/rc.microvmss start_containerd 2>&1", $o1, $r1);
            exec("/etc/rc.d/rc.microvmss start_registry 2>&1", $o2, $r2);
            exec("/etc/rc.d/rc.microvmss start_flintlockd 2>&1", $o3, $r3);
            $allOk = ($r1 === 0 && $r3 === 0);
            echo json_encode([
                'success' => $allOk,
                'message' => implode("\n", array_merge($o1, $o2, $o3)),
            ]);
        } elseif ($action === 'stop') {
            // Stop flintlockd, registry, containerd
            exec("/etc/rc.d/rc.microvmss stop_flintlockd 2>&1", $o1, $r1);
            exec("/etc/rc.d/rc.microvmss stop_registry 2>&1", $o2, $r2);
            exec("/etc/rc.d/rc.microvmss stop_containerd 2>&1", $o3, $r3);
            echo json_encode([
                'success' => true,
                'message' => implode("\n", array_merge($o1, $o2, $o3)),
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid action (start/stop/status)']);
        }
        break;

    case 'prune_images':
        $sock = '/var/run/microvms/containerd.sock';
        $output = [];
        $out = shell_exec("ctr -a $sock -n default images prune --all 2>&1");
        if ($out) $output[] = trim($out);
        echo json_encode(['success' => true, 'message' => implode("\n", $output) ?: 'No unused images to prune']);
        break;

    case 'delete_rootfs':
        $rootfsPath = "$vmdir/$name";
        // Check VM is not running
        if (file_exists("/tmp/microvms-{$name}.sock")) {
            echo json_encode(['success' => false, 'error' => "VM '$name' is running. Stop it first."]);
            break;
        }
        // Only delete rootfs file, keep config
        $deleted = false;
        foreach (['rootfs.raw', 'rootfs.ext4'] as $rf) {
            $fp = "$rootfsPath/$rf";
            if (file_exists($fp)) {
                unlink($fp);
                $deleted = true;
                microvm_log("DELETED rootfs: $fp");
            }
        }
        echo json_encode(['success' => $deleted, 'message' => $deleted ? "RootFS deleted for '$name'" : "No rootfs found"]);
        break;

    case 'pull_rootfs':
        $image = $_POST['image'] ?? 'nginx:alpine';
        $pullName = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['name'] ?? ''));
        $diskSize = intval($_POST['disk_size'] ?? 500);
        if (empty($pullName)) {
            echo json_encode(['success' => false, 'error' => 'Invalid VM name']);
            break;
        }
        $vmPath = "$vmdir/$pullName";
        $rootfs = "$vmPath/rootfs.raw";
        @mkdir($vmPath, 0755, true);

        // Pull with crane to temp tar
        $tmpTar = "/tmp/microvm-$pullName.tar";
        exec("crane export " . escapeshellarg($image) . " " . escapeshellarg($tmpTar) . " 2>&1", $pullOutput, $pullRet);
        if ($pullRet !== 0) {
            echo json_encode(['success' => false, 'error' => 'crane pull failed: ' . implode("\n", $pullOutput)]);
            break;
        }

        // Create ext4 image (raw storage: dd sparse file → mkfs.ext4 → mount → extract → umount)
        exec("dd if=/dev/zero of=$rootfs bs=1M count=$diskSize 2>/dev/null");
        exec("mkfs.ext4 -F $rootfs 2>/dev/null");
        exec("mkdir -p /tmp/microvm-mount-$pullName");
        exec("mount $rootfs /tmp/microvm-mount-$pullName");
        exec("tar -xf $tmpTar -C /tmp/microvm-mount-$pullName 2>&1");

        // Get OCI image entrypoint and cmd
        $entrypoint = '';
        $cmd = '';
        $imageConfig = shell_exec("crane config " . escapeshellarg($image) . " 2>/dev/null");
        if ($imageConfig) {
            $imgCfg = json_decode($imageConfig, true);
            $ep = $imgCfg['config']['Entrypoint'] ?? [];
            $cm = $imgCfg['config']['Cmd'] ?? [];
            $entrypoint = implode(' ', $ep);
            $cmd = implode(' ', $cm);
        }
        $execCmd = trim("$entrypoint $cmd");
        if (empty($execCmd)) $execCmd = '/bin/sh';

        // Inject init script with network configuration and OCI entrypoint
        $pullInitScript = <<<INIT
#!/bin/sh
mount -t proc proc /proc
mount -t sysfs sysfs /sys
mount -t devtmpfs devtmpfs /dev 2>/dev/null
mkdir -p /dev/pts && mount -t devpts devpts /dev/pts

# Network config from kernel cmdline
for d in /sys/class/net/*; do n=\$(basename \$d); [ "\$n" != "lo" ] && IFACE=\$n && break; done
IFACE=\${IFACE:-eth0}
CMDLINE=\$(cat /proc/cmdline)
IP=\$(echo "\$CMDLINE" | grep -o 'ip=[^ ]*' | head -1 | sed 's/ip=//' | cut -d: -f1)
GW=\$(echo "\$CMDLINE" | grep -o 'ip=[^ ]*' | head -1 | sed 's/ip=//' | cut -d: -f3)
if [ -n "\$IP" ]; then
  ip link set lo up 2>/dev/null
  ip link set \$IFACE up 2>/dev/null
  ip addr add \${IP}/24 dev \$IFACE 2>/dev/null
  [ -n "\$GW" ] && ip route add default via \$GW dev \$IFACE 2>/dev/null
fi
echo "nameserver 8.8.8.8" > /etc/resolv.conf 2>/dev/null
echo "nameserver 1.1.1.1" >> /etc/resolv.conf 2>/dev/null

# Run OCI entrypoint + cmd (no console)
exec $execCmd
INIT;
        file_put_contents("/tmp/microvm-mount-$pullName/init", $pullInitScript);
        chmod("/tmp/microvm-mount-$pullName/init", 0755);

        exec("umount /tmp/microvm-mount-$pullName");
        exec("rmdir /tmp/microvm-mount-$pullName 2>/dev/null");
        // Cleanup temp tar
        @unlink($tmpTar);

        microvm_log("PULL_ROOTFS: $image -> $rootfs ($diskSize MB)");
        echo json_encode(['success' => true, 'path' => $rootfs]);
        break;

    case 'vm_log':
        // VM-level output (kernel boot + init)
        // FC: the process log IS the serial output
        // CH: serial PTY output captured to a separate .serial.log file
        $engine = $_REQUEST['engine'] ?? 'cloud-hypervisor';
        $allowed_vmms = ['cloud-hypervisor', 'firecracker'];
        if (!in_array($engine, $allowed_vmms) || !preg_match('/^[a-z0-9\-]+$/', $name)) {
            echo json_encode(['success' => false, 'error' => "Invalid engine or name"]);
            break;
        }
        if ($engine === 'firecracker') {
            $logfile = "/var/log/microvms/firecracker/{$name}.log";
        } else {
            $logfile = "/var/log/microvms/cloud-hypervisor/{$name}.serial.log";
        }
        if (file_exists($logfile)) {
            $log = shell_exec("tail -100 " . escapeshellarg($logfile) . " 2>/dev/null");
            echo json_encode(['success' => true, 'log' => $log ?: '(empty)']);
        } else {
            echo json_encode(['success' => true, 'log' => '(no VM output captured yet)']);
        }
        break;

    case 'console_output':
        // Read serial log for Console output box
        $name = $_REQUEST['name'] ?? '';
        if (!preg_match('/^[a-z0-9\-]+$/', $name)) {
            echo json_encode(['success' => false, 'error' => 'Invalid name']);
            break;
        }
        $cfg = microvm_load_config();
        $vmdir = $cfg['VMDIR'] ?? '/mnt/user/microvms';
        $configFile = microvm_find_config_file("$vmdir/$name");
        $vmm = $configFile ? microvm_get_vmm($configFile) : 'cloud-hypervisor';
        if ($vmm === 'cloud-hypervisor') {
            $logfile = "/var/log/microvms/cloud-hypervisor/{$name}.serial.log";
        } else {
            $logfile = "/var/log/microvms/firecracker/{$name}.log";
        }
        if (file_exists($logfile)) {
            $log = shell_exec("tail -100 " . escapeshellarg($logfile) . " 2>/dev/null");
            $log = preg_replace('/\033\[[0-9;]*[a-zA-Z]/', '', $log ?: '');
            echo json_encode(['success' => true, 'log' => $log ?: '(empty)']);
        } else {
            echo json_encode(['success' => true, 'log' => '(no output yet)']);
        }
        break;

    case 'console_input':
        // Send command to VM via FIFO
        $name = $_REQUEST['name'] ?? '';
        $input = $_REQUEST['input'] ?? '';
        if (!preg_match('/^[a-z0-9\-]+$/', $name)) {
            echo json_encode(['success' => false, 'error' => 'Invalid name']);
            break;
        }
        $fifo = "/tmp/microvms-{$name}.fifo";
        if (file_exists($fifo)) {
            $escaped = str_replace("'", "'\\''", $input);
            exec("printf '%s\\n' '$escaped' > " . escapeshellarg($fifo) . " 2>&1 &");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'No FIFO available. Is the VM running?']);
        }
        break;

    case 'view_log':
        $service = $_REQUEST['service'] ?? '';
        $log_map = [
            'containerd' => '/var/log/microvms/containerd.log',
            'flintlockd' => '/var/log/microvms/flintlockd.log',
            'registry' => '/var/log/microvms/registry.log',
        ];

        if (strpos($service, '/') !== false) {
            // Per-VM log: validate vmm/name pattern (e.g. cloud-hypervisor/ch-nginx-test)
            $parts = explode('/', $service, 2);
            $vmm_part = $parts[0];
            $name_part = $parts[1] ?? '';
            $allowed_vmms = ['cloud-hypervisor', 'firecracker'];
            if (!in_array($vmm_part, $allowed_vmms) || !preg_match('/^[a-z0-9\-]+$/', $name_part)) {
                echo json_encode(['success' => false, 'error' => "Invalid VM log path: $service"]);
                break;
            }
            // CH: serial.log has kernel+boot output; FC: .log IS kernel+boot
            if ($vmm_part === 'cloud-hypervisor') {
                $logfile = "/var/log/microvms/$vmm_part/$name_part.serial.log";
            } else {
                $logfile = "/var/log/microvms/$vmm_part/$name_part.log";
            }
        } elseif (isset($log_map[$service])) {
            $logfile = $log_map[$service];
        } else {
            echo json_encode(['success' => false, 'error' => "Unknown service: $service"]);
            break;
        }

        if (file_exists($logfile)) {
            // Show full log (boot + app + kernel events)
            $log = shell_exec("tail -200 " . escapeshellarg($logfile) . " 2>/dev/null");
            // Strip ANSI escape sequences (colors, cursor queries)
            $log = preg_replace('/\033\[[0-9;]*[a-zA-Z]/', '', $log ?: '');
            echo json_encode(['success' => true, 'log' => $log ?: '(empty)']);
        } else {
            echo json_encode(['success' => true, 'log' => '(log file does not exist yet)']);
        }
        break;

    case 'download_kernels':
        $dl_ch = ($_POST['cloud_hypervisor'] ?? '0') === '1';
        $dl_fc = ($_POST['firecracker'] ?? '0') === '1';
        $kernelDir = "/mnt/user/system/microvms";
        $results = [];
        $errors = [];

        if ($dl_ch) {
            $chDir = "$kernelDir/cloud-hypervisor/kernels";
            exec("mkdir -p " . escapeshellarg($chDir));
            $url = "https://github.com/cloud-hypervisor/linux/releases/download/ch-release-v6.2-20240908/vmlinux";
            exec("curl -fsSL -o " . escapeshellarg("$chDir/vmlinux") . " " . escapeshellarg($url) . " 2>&1", $out, $ret);
            if ($ret === 0 && filesize("$chDir/vmlinux") > 1000000) {
                $results[] = "Cloud Hypervisor kernel: " . round(filesize("$chDir/vmlinux") / 1048576) . " MB";
            } else {
                $errors[] = "Cloud Hypervisor kernel download failed";
                @unlink("$chDir/vmlinux");
            }
        }

        if ($dl_fc) {
            $fcDir = "$kernelDir/firecracker/kernels";
            exec("mkdir -p " . escapeshellarg($fcDir));
            $url = "https://s3.amazonaws.com/spec.ccfc.min/firecracker-ci/v1.11/x86_64/vmlinux-5.10.225";
            exec("curl -fsSL -o " . escapeshellarg("$fcDir/vmlinux") . " " . escapeshellarg($url) . " 2>&1", $out, $ret);
            if ($ret === 0 && filesize("$fcDir/vmlinux") > 1000000) {
                $results[] = "Firecracker kernel: " . round(filesize("$fcDir/vmlinux") / 1048576) . " MB";
            } else {
                $errors[] = "Firecracker kernel download failed";
                @unlink("$fcDir/vmlinux");
            }
        }

        if (empty($errors)) {
            echo json_encode(['success' => true, 'message' => implode("\n", $results)]);
        } else {
            echo json_encode(['success' => false, 'error' => implode("; ", $errors), 'partial' => $results]);
        }
        microvm_log("DOWNLOAD_KERNELS: " . implode(", ", $results) . " " . implode(", ", $errors));
        break;

    case 'download_kernel':
        // Single kernel download (from Settings page per-VMM button)
        $engine = $_REQUEST['engine'] ?? '';
        $kernelDir = '/mnt/user/system/microvms';
        $customUrl = '';

        if ($engine === 'cloud-hypervisor') {
            $cfg = microvm_load_config();
            $customUrl = $cfg['CH_KERNEL_URL'] ?? '';
            $dir = "$kernelDir/cloud-hypervisor/kernels";
            $url = $customUrl ?: 'https://github.com/cloud-hypervisor/rust-hypervisor-firmware/releases/download/0.4.2/hypervisor-fw';
            exec("mkdir -p " . escapeshellarg($dir));
            exec("curl -fsSL -o " . escapeshellarg("$dir/vmlinux") . " " . escapeshellarg($url) . " 2>&1", $out, $ret);
            if ($ret === 0 && filesize("$dir/vmlinux") > 100000) {
                echo json_encode(['success' => true, 'message' => "Cloud Hypervisor kernel downloaded: " . round(filesize("$dir/vmlinux") / 1048576) . " MB"]);
            } else {
                @unlink("$dir/vmlinux");
                echo json_encode(['success' => false, 'error' => "Download failed (exit=$ret)"]);
            }
        } elseif ($engine === 'firecracker') {
            $cfg = microvm_load_config();
            $customUrl = $cfg['FC_KERNEL_URL'] ?? '';
            $dir = "$kernelDir/firecracker/kernels";
            $url = $customUrl ?: 'https://s3.amazonaws.com/spec.ccfc.min/firecracker-ci/v1.11/x86_64/vmlinux-5.10.225';
            exec("mkdir -p " . escapeshellarg($dir));
            exec("curl -fsSL -o " . escapeshellarg("$dir/vmlinux") . " " . escapeshellarg($url) . " 2>&1", $out, $ret);
            if ($ret === 0 && filesize("$dir/vmlinux") > 1000000) {
                echo json_encode(['success' => true, 'message' => "Firecracker kernel downloaded: " . round(filesize("$dir/vmlinux") / 1048576) . " MB"]);
            } else {
                @unlink("$dir/vmlinux");
                echo json_encode(['success' => false, 'error' => "Download failed (exit=$ret)"]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => "Unknown engine: $engine"]);
        }
        break;

    case 'service_action':
        // Start/stop individual services (flintlockd only from UI)
        $service = $_REQUEST['service'] ?? '';
        $action = $_REQUEST['action'] ?? '';

        $allowed = ['flintlockd', 'containerd', 'registry'];
        if (!in_array($service, $allowed)) {
            echo json_encode(['success' => false, 'error' => "Service not controllable: $service"]);
            break;
        }
        if (!in_array($action, ['start', 'stop', 'restart'])) {
            echo json_encode(['success' => false, 'error' => "Invalid action: $action"]);
            break;
        }

        if ($service === 'flintlockd') {
            if ($action === 'start') {
                exec("/etc/rc.d/rc.microvms start_flintlockd 2>&1", $out, $ret);
            } elseif ($action === 'restart') {
                exec("/etc/rc.d/rc.microvms stop_flintlockd 2>&1", $out, $ret);
                sleep(1);
                exec("/etc/rc.d/rc.microvms start_flintlockd 2>&1", $out, $ret);
            } else {
                exec("/etc/rc.d/rc.microvms stop_flintlockd 2>&1", $out, $ret);
            }
            echo json_encode(['success' => ($ret === 0), 'output' => implode("\n", $out)]);
            microvm_log("SERVICE_ACTION: $service $action (exit=$ret)");
        } elseif ($service === 'containerd') {
            if ($action === 'start') {
                exec("/etc/rc.d/rc.microvms start_containerd 2>&1", $out, $ret);
            } elseif ($action === 'restart') {
                exec("/etc/rc.d/rc.microvms stop_containerd 2>&1", $out, $ret);
                sleep(1);
                exec("/etc/rc.d/rc.microvms start_containerd 2>&1", $out, $ret);
            } else {
                exec("/etc/rc.d/rc.microvms stop_containerd 2>&1", $out, $ret);
            }
            echo json_encode(['success' => ($ret === 0), 'output' => implode("\n", $out)]);
            microvm_log("SERVICE_ACTION: $service $action (exit=$ret)");
        } elseif ($service === 'registry') {
            $out = [];
            if ($action === 'start') {
                exec("/etc/rc.d/rc.microvms start_registry 2>&1", $out, $ret);
            } elseif ($action === 'restart') {
                $pid = trim(shell_exec("pgrep -f 'crane.registry' 2>/dev/null"));
                if ($pid) exec("kill $pid 2>/dev/null");
                sleep(1);
                exec("/etc/rc.d/rc.microvms start_registry 2>&1", $out, $ret);
            } else {
                $pid = trim(shell_exec("pgrep -f 'crane.registry' 2>/dev/null"));
                if ($pid) exec("kill $pid 2>/dev/null");
                @unlink('/var/run/microvms/crane-registry.pid');
                $ret = 0;
            }
            echo json_encode(['success' => ($ret === 0), 'output' => implode("\n", $out)]);
            microvm_log("SERVICE_ACTION: $service $action (exit=$ret)");
        }
        break;

    case 'toggle_setting':
        // Toggle a single config key (for Enable/Disable buttons in service grid)
        $key = $_REQUEST['key'] ?? '';
        $value = $_REQUEST['value'] ?? '';
        $allowed_keys = ['CH_ENABLED', 'FC_ENABLED', 'FLINTLOCKD', 'DEVMAPPER'];
        if (!in_array($key, $allowed_keys)) {
            echo json_encode(['success' => false, 'error' => "Not allowed: $key"]);
            break;
        }

        $cfgFile = "/boot/config/plugins/microvms/microvms.controlplane.cfg";
        $cfg = microvm_load_config();
        $vmdir = $cfg['VMDIR'] ?? '/mnt/user/microvms';

        // Guard: cannot disable devmapper if any VM uses thin pool storage
        if ($key === 'DEVMAPPER' && $value === 'disable') {
            $thin_vms = [];
            if (is_dir($vmdir)) {
                foreach (glob("$vmdir/*/*.json") as $f) {
                    $vm = json_decode(file_get_contents($f), true);
                    if ($vm && ($vm['storage']['type'] ?? '') === 'thin') {
                        $thin_vms[] = $vm['name'] ?? basename(dirname($f));
                    }
                }
            }
            if (!empty($thin_vms)) {
                echo json_encode(['success' => false, 'error' => "Cannot disable devmapper: " . count($thin_vms) . " VM(s) use thin pool storage: " . implode(', ', $thin_vms)]);
                break;
            }
        }

        $cfg[$key] = $value;
        // Write back
        $lines = [];
        foreach ($cfg as $k => $v) {
            $lines[] = "$k=\"$v\"";
        }
        file_put_contents($cfgFile, implode("\n", $lines) . "\n");
        // Restart service to apply
        exec("/etc/rc.d/rc.microvms restart 2>&1", $out, $ret);
        echo json_encode(['success' => true, 'message' => "$key set to $value"]);
        microvm_log("TOGGLE_SETTING: $key=$value (restart exit=$ret)");
        break;

    default:
        echo json_encode(['error' => "Unknown command: $cmd"]);
}
