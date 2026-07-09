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

// Resolve namespace: from request, or scan dirs to find existing VM
$namespace = $_REQUEST['namespace'] ?? '';
if (empty($namespace) && !empty($name)) {
    // Scan all namespace dirs to find this VM
    foreach (glob("$vmdir/*/") as $nsDir) {
        if (is_dir("$nsDir$name")) {
            $namespace = basename($nsDir);
            break;
        }
    }
}
if (empty($namespace)) $namespace = 'default';
$vmpath = "$vmdir/$namespace/$name";

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
        $configFile = microvm_find_config_file("$vmpath");
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
            $configFile = microvm_find_config_file("$vmpath");
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

    case 'vm_stats':
        // Return live stats for all running VMs (polled by AJAX)
        $stats = [];
        // Find all VMM PIDs
        foreach (['cloud-hypervisor', 'firecracker'] as $bin) {
            $pids = trim(shell_exec("pidof $bin 2>/dev/null"));
            if (!$pids) continue;
            foreach (explode(' ', $pids) as $pid) {
                $cmdline = @file_get_contents("/proc/$pid/cmdline");
                if (!$cmdline) continue;
                $vmName = '';
                $vmm = $bin;
                // WebGUI VMs
                if (preg_match('/microvms-([a-z0-9\-]+)\.sock/', $cmdline, $m)) {
                    $vmName = $m[1];
                }
                // Flintlockd VMs
                if (!$vmName && preg_match('/flintlockd-state\/vm\/[^\/]+\/([a-z0-9\-]+)\//', $cmdline, $m)) {
                    $vmName = $m[1];
                }
                if (!$vmName) continue;

                // Find TAP device from cmdline
                $tap = '';
                if (preg_match('/tap=([a-zA-Z0-9]+)/', $cmdline, $m)) {
                    $tap = $m[1];
                }

                // CPU% from /proc/PID/stat
                $cpuPercent = 0;
                $stat = @file_get_contents("/proc/$pid/stat");
                if ($stat) {
                    $parts = preg_split('/\)\s+/', $stat, 2);
                    if (isset($parts[1])) {
                        $fields = explode(' ', $parts[1]);
                        $utime = intval($fields[11] ?? 0);
                        $stime = intval($fields[12] ?? 0);
                        $starttime = intval($fields[19] ?? 0);
                        $uptime = floatval(explode(' ', @file_get_contents('/proc/uptime'))[0]);
                        $elapsed = $uptime - ($starttime / 100);
                        if ($elapsed > 0) $cpuPercent = round((($utime + $stime) / 100) / $elapsed * 100, 1);
                    }
                }

                // RSS from /proc/PID/status
                $rssMb = 0;
                $status = @file_get_contents("/proc/$pid/status");
                if ($status && preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $m)) {
                    $rssMb = round($m[1] / 1024);
                }

                // Disk I/O from /proc/PID/io
                $readMb = 0; $writeMb = 0;
                $io = @file_get_contents("/proc/$pid/io");
                if ($io) {
                    if (preg_match('/read_bytes:\s+(\d+)/', $io, $m)) $readMb = round($m[1] / 1048576, 1);
                    if (preg_match('/write_bytes:\s+(\d+)/', $io, $m)) $writeMb = round($m[1] / 1048576, 1);
                }

                // Network I/O from /sys/class/net/{tap}/statistics/
                $netRx = 0; $netTx = 0;
                if ($tap && is_dir("/sys/class/net/$tap/statistics")) {
                    $netRx = round(intval(@file_get_contents("/sys/class/net/$tap/statistics/rx_bytes")) / 1048576, 1);
                    $netTx = round(intval(@file_get_contents("/sys/class/net/$tap/statistics/tx_bytes")) / 1048576, 1);
                }

                // Uptime
                $uptimeSec = 0;
                if ($stat && isset($parts[1])) {
                    $fields2 = explode(' ', $parts[1]);
                    $starttime2 = intval($fields2[19] ?? 0);
                    $sysUptime = floatval(explode(' ', @file_get_contents('/proc/uptime'))[0]);
                    $uptimeSec = round($sysUptime - ($starttime2 / 100));
                }

                // Extract vCPUs and memory from cmdline
                $vcpus = 0; $memCurrent = 0; $memMax = 0;
                if (preg_match('/boot=(\d+)/', $cmdline, $m)) $vcpus = intval($m[1]);
                if (preg_match('/size=(\d+)M/', $cmdline, $m)) $memCurrent = intval($m[1]);
                if (preg_match('/hotplug_size=(\d+)M/', $cmdline, $m)) $memMax = $memCurrent + intval($m[1]);
                if ($memMax === 0) $memMax = $memCurrent;
                // FC: memory from --config-file or from /proc/PID/cmdline
                if ($vcpus === 0 && preg_match('/vcpu_count.*?(\d+)/', $cmdline, $m)) $vcpus = intval($m[1]);
                if ($memCurrent === 0 && preg_match('/mem_size_mib.*?(\d+)/', $cmdline, $m)) $memCurrent = intval($m[1]);

                $stats[] = [
                    'name' => $vmName,
                    'vmm' => $vmm,
                    'pid' => intval($pid),
                    'tap' => $tap,
                    'vcpus' => $vcpus,
                    'mem_current' => $memCurrent,
                    'mem_max' => $memMax,
                    'cpu_percent' => $cpuPercent,
                    'rss_mb' => $rssMb,
                    'disk_read_mb' => $readMb,
                    'disk_write_mb' => $writeMb,
                    'net_rx_mb' => $netRx,
                    'net_tx_mb' => $netTx,
                    'uptime' => $uptimeSec,
                ];
            }
        }
        echo json_encode(['success' => true, 'stats' => $stats]);
        break;

    case 'resize':
        // Only Cloud Hypervisor supports live resize via ch-remote
        $configFile = microvm_find_config_file("$vmpath");
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
        $vmPath = "$vmpath";

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

        // Remove from containerd registry (search all namespaces)
        $ctrSock = '/var/run/microvms/containerd.sock';
        $nsList = trim(shell_exec("ctr -a $ctrSock namespaces list -q 2>/dev/null"));
        foreach (explode("\n", $nsList) as $ns) {
            $ns = trim($ns);
            if (empty($ns)) continue;
            exec("ctr -a $ctrSock -n $ns containers rm " . escapeshellarg($name) . " 2>/dev/null");
        }
        // Remove state directory
        exec("rm -rf /var/run/microvms/*/" . escapeshellarg($name) . " 2>/dev/null");

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
        $vmPath = "$vmpath";
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
                $ctr = "ctr -a $sock -n " . escapeshellarg($namespace);

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
            'namespace' => ($_REQUEST['namespace'] ?? 'default') === 'flintlock' ? 'default' : ($_REQUEST['namespace'] ?? 'default'),
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

        // Register in containerd (source of truth for VM list)
        // Container lives in its VMM namespace (ch/fc/default)
        $ctrSock = '/var/run/microvms/containerd.sock';
        if (file_exists($ctrSock)) {
            $ociRef = $config['image']['ref'] ?? 'docker.io/library/alpine:latest';
            // Normalize: docker.io/nginx → docker.io/library/nginx
            if (!str_contains($ociRef, '/')) {
                $ociRef = "docker.io/library/$ociRef";
            } elseif (preg_match('#^docker\.io/([^/]+)$#', $ociRef, $m)) {
                $ociRef = "docker.io/library/" . $m[1];
            } elseif (!str_contains($ociRef, '.') && substr_count($ociRef, '/') === 1) {
                $ociRef = "docker.io/library/$ociRef";
            }
            if (!str_contains($ociRef, ':')) $ociRef .= ':latest';
            $ns = $namespace;
            exec("ctr -a $ctrSock namespaces create " . escapeshellarg($ns) . " 2>/dev/null");
            // Ensure image exists in target namespace (content shared, just metadata)
            exec("ctr -a $ctrSock -n " . escapeshellarg($ns) . " images pull " . escapeshellarg($ociRef) . " 2>/dev/null");
            exec("ctr -a $ctrSock -n " . escapeshellarg($ns) . " containers rm " . escapeshellarg($name) . " 2>/dev/null");
            $labelArgs = "--label microvm.vmm=" . escapeshellarg($vmm)
                . " --label microvm.state=created"
                . " --label microvm.pid=0"
                . " --label microvm.namespace=" . escapeshellarg($ns)
                . " --label microvm.ip=" . escapeshellarg($config['network']['ip'] ?? '')
                . " --label microvm.tap=tap" . ($config['network']['tap_id'] ?? 0);
            exec("ctr -a $ctrSock -n " . escapeshellarg($ns) . " containers create $labelArgs " . escapeshellarg($ociRef) . " " . escapeshellarg($name) . " 2>&1", $ctrOut, $ctrRet);
            if ($ctrRet !== 0) {
                microvm_log("WARN: containerd registration failed for $name: " . implode(' ', $ctrOut));
            }
        }

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
        $vmPath = "$vmpath";
        @mkdir($vmPath, 0755, true);
        // Write as {vmm}.json
        $configFilename = "$vmPath/$vmm.json";
        file_put_contents($configFilename, json_encode($config, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'message' => "VM '$name' created from JSON."]);
        break;

    case 'console':
        // Serial console via ttyd + unix socket (proxied by Unraid nginx at /logterminal/)
        $logFile = "$vmpath/vm.log";
        if (!file_exists($logFile)) $logFile = microvm_get_log_path($name, $vmdir);
        $sock = "/tmp/microvms-{$name}.sock";

        // Check VM is running
        if (!file_exists($sock)) {
            echo json_encode(['success' => false, 'error' => "VM '$name' is not running"]);
            break;
        }

        // Detect engine
        $configFile = microvm_find_config_file("$vmpath");
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
        $logFile = "$vmpath/vm.log";
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
        $logFile = "$vmpath/vm.log";
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
        $configFile = microvm_find_config_file("$vmpath");
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

    case 'flintlock_start':
        // Restart flintlockd to force immediate reconcile (resync detects pending VMs and starts them)
        $uid = $_REQUEST['uid'] ?? '';
        if (empty($uid)) {
            echo json_encode(['success' => false, 'error' => 'No UID provided']);
            break;
        }
        // Check if VM spec still exists
        $out = shell_exec("grpcurl -plaintext -d " . escapeshellarg(json_encode(['uid' => $uid])) . " 0.0.0.0:9090 microvm.services.api.v1alpha1.MicroVM/GetMicroVM 2>&1");
        if (strpos($out, 'not found') !== false || strpos($out, 'Error') !== false) {
            echo json_encode(['success' => false, 'error' => "VM spec not found in flintlockd (UID: $uid). The VM must be recreated via gRPC CreateMicroVM."]);
            break;
        }
        // Restart flintlockd (cleans leases + triggers immediate resync)
        exec("/etc/rc.d/rc.microvms stop_flintlockd 2>/dev/null");
        sleep(2);
        exec("/etc/rc.d/rc.microvms start_flintlockd 2>&1", $startOut);
        sleep(5);
        echo json_encode(['success' => true, 'message' => "Flintlockd restarted. Reconcile triggered — VM should start within seconds."]);
        break;

    case 'flintlock_force_stop':
        // Kill the flintlockd VM process directly
        $flState = '/var/run/microvms/flintlockd-state/vm';
        $pid = '';
        // Find PID from state dir
        foreach (glob("$flState/*/$name/*/cloudhypervisor.pid") as $pidFile) {
            $pid = trim(@file_get_contents($pidFile));
            break;
        }
        if (empty($pid)) {
            foreach (glob("$flState/*/$name/*/firecracker.pid") as $pidFile) {
                $pid = trim(@file_get_contents($pidFile));
                break;
            }
        }
        if ($pid && file_exists("/proc/$pid")) {
            exec("kill -9 $pid 2>/dev/null");
            echo json_encode(['success' => true, 'message' => "Force killed flintlockd VM '$name' (PID $pid). Flintlockd may restart it on next reconcile."]);
        } else {
            echo json_encode(['success' => false, 'error' => "No running process found for flintlockd VM '$name'"]);
        }
        break;

    case 'flintlock_delete':
        // Delete via gRPC API
        $uid = $_REQUEST['uid'] ?? '';
        if (empty($uid)) {
            echo json_encode(['success' => false, 'error' => 'No UID provided']);
            break;
        }
        $out = shell_exec("grpcurl -plaintext -d " . escapeshellarg(json_encode(['uid' => $uid])) . " 0.0.0.0:9090 microvm.services.api.v1alpha1.MicroVM/DeleteMicroVM 2>&1");
        if (strpos($out, 'ERROR') !== false || strpos($out, 'Error') !== false) {
            echo json_encode(['success' => false, 'error' => $out]);
        } else {
            echo json_encode(['success' => true, 'message' => "Flintlockd VM '$name' deleted via gRPC.", 'output' => $out]);
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

    case 'storage_info':
        // Return thin pool status, volumes, and images across all namespaces
        $sock = '/var/run/microvms/containerd.sock';
        $result = ['thin_pool' => null, 'volumes' => [], 'images' => []];

        // --- Thin Pool ---
        $dmStatus = trim(shell_exec("dmsetup status microvms-thinpool 2>/dev/null"));
        if ($dmStatus) {
            // Format: 0 <total_sectors> thin-pool <transaction_id> <used_meta>/<total_meta> <used_data>/<total_data> ...
            $parts = preg_split('/\s+/', $dmStatus);
            $dataUsed = 0; $dataTotal = 0; $metaUsed = 0; $metaTotal = 0;
            if (count($parts) >= 7) {
                // parts[4] = used_meta/total_meta, parts[5] = used_data/total_data
                if (preg_match('#^(\d+)/(\d+)$#', $parts[4], $m)) {
                    $metaUsed = intval($m[1]);
                    $metaTotal = intval($m[2]);
                }
                if (preg_match('#^(\d+)/(\d+)$#', $parts[5], $m)) {
                    $dataUsed = intval($m[1]);
                    $dataTotal = intval($m[2]);
                }
            }
            // Each block = 512 bytes (sectors)
            $result['thin_pool'] = [
                'status' => 'active',
                'data_used_mb' => round($dataUsed * 512 / 1048576, 1),
                'data_total_mb' => round($dataTotal * 512 / 1048576, 1),
                'data_percent' => $dataTotal > 0 ? round($dataUsed / $dataTotal * 100, 1) : 0,
                'meta_used_mb' => round($metaUsed * 512 / 1048576, 2),
                'meta_total_mb' => round($metaTotal * 512 / 1048576, 2),
                'device' => 'microvms-thinpool',
            ];
        } else {
            $result['thin_pool'] = ['status' => 'inactive', 'data_used_mb' => 0, 'data_total_mb' => 0, 'data_percent' => 0, 'meta_used_mb' => 0, 'meta_total_mb' => 0, 'device' => 'microvms-thinpool'];
        }

        // --- Volumes (thin snapshots + raw files) ---
        // Load all VM configs to map volumes to VMs
        $vmConfigs = [];
        foreach (glob("$vmdir/*/*/*.json") as $cfgFile) {
            $vc = json_decode(file_get_contents($cfgFile), true);
            if ($vc && !empty($vc['name'])) {
                $vcNs = basename(dirname(dirname($cfgFile)));
                $vmConfigs[] = ['name' => $vc['name'], 'namespace' => $vcNs, 'config' => $vc];
            }
        }

        // Thin volumes from containerd devmapper snapshots
        $nsList = trim(shell_exec("ctr -a $sock namespaces list -q 2>/dev/null"));
        $namespaces = array_filter(explode("\n", $nsList));
        foreach ($namespaces as $ns) {
            $ns = trim($ns);
            if (empty($ns)) continue;
            $snapList = shell_exec("ctr -a $sock -n " . escapeshellarg($ns) . " snapshots --snapshotter devmapper list 2>/dev/null");
            if (!$snapList) continue;
            $lines = explode("\n", trim($snapList));
            // First line is header: KEY PARENT KIND
            array_shift($lines);
            foreach ($lines as $line) {
                $cols = preg_split('/\s+/', trim($line), 3);
                if (count($cols) < 3) continue;
                $snapKey = $cols[0];
                $snapKind = $cols[2] ?? '';
                $status = (strtolower($snapKind) === 'active') ? 'active' : 'committed';
                // Determine which VM uses this snapshot
                $usedBy = 'unused';
                foreach ($vmConfigs as $vc) {
                    if (strpos($snapKey, $vc['name']) !== false) {
                        $usedBy = $vc['name'];
                        break;
                    }
                }
                $result['volumes'][] = [
                    'name' => $snapKey,
                    'namespace' => $ns,
                    'type' => 'thin',
                    'size_mb' => 0,
                    'device' => "devmapper/$snapKey",
                    'status' => $status,
                    'used_by' => $usedBy,
                ];
            }
        }

        // Raw volumes: scan $vmdir/*/*/rootfs.raw
        foreach (glob("$vmdir/*/*/rootfs.raw") as $rawFile) {
            $vmName = basename(dirname($rawFile));
            $nsName = basename(dirname(dirname($rawFile)));
            $sizeMb = round(filesize($rawFile) / 1048576, 1);
            // Check if VM is running
            $running = file_exists("/tmp/microvms-{$vmName}.sock");
            $result['volumes'][] = [
                'name' => "$vmName/rootfs.raw",
                'namespace' => $nsName,
                'type' => 'raw',
                'size_mb' => $sizeMb,
                'device' => $rawFile,
                'status' => $running ? 'active' : 'committed',
                'used_by' => $vmName,
            ];
        }

        // --- Images ---
        foreach ($namespaces as $ns) {
            $ns = trim($ns);
            if (empty($ns)) continue;
            $imgList = shell_exec("ctr -a $sock -n " . escapeshellarg($ns) . " images list 2>/dev/null");
            if (!$imgList) continue;
            $lines = explode("\n", trim($imgList));
            // First line is header: REF TYPE DIGEST SIZE PLATFORMS LABELS
            array_shift($lines);
            foreach ($lines as $line) {
                $cols = preg_split('/\s+/', trim($line));
                if (count($cols) < 4) continue;
                $ref = $cols[0];
                // Size is 4th column (e.g. "5.2 MiB" or "45.1 MiB")
                $sizeStr = $cols[3] ?? '0';
                $sizeMb = 0;
                if (preg_match('/^([\d.]+)\s*$/i', $sizeStr, $sm)) {
                    // Next col might be unit
                    $unit = $cols[4] ?? 'B';
                    if (stripos($unit, 'MiB') !== false || stripos($unit, 'MB') !== false) {
                        $sizeMb = floatval($sm[1]);
                    } elseif (stripos($unit, 'GiB') !== false || stripos($unit, 'GB') !== false) {
                        $sizeMb = floatval($sm[1]) * 1024;
                    } elseif (stripos($unit, 'KiB') !== false || stripos($unit, 'KB') !== false) {
                        $sizeMb = round(floatval($sm[1]) / 1024, 2);
                    }
                }
                // Match image to VMs
                $usedByVms = [];
                foreach ($vmConfigs as $vc) {
                    $imgRef = $vc['config']['image']['ref'] ?? '';
                    $storageRef = $vc['config']['storage']['image_ref'] ?? '';
                    if ($imgRef === $ref || $storageRef === $ref || strpos($ref, $imgRef) !== false) {
                        $usedByVms[] = $vc['name'];
                    }
                }
                $result['images'][] = [
                    'ref' => $ref,
                    'namespace' => $ns,
                    'size_mb' => $sizeMb,
                    'used_by' => $usedByVms,
                ];
            }
        }

        microvm_log("STORAGE_INFO: pool=" . ($result['thin_pool']['status'] ?? 'unknown') . " volumes=" . count($result['volumes']) . " images=" . count($result['images']));
        echo json_encode(['success' => true, 'data' => $result]);
        break;

    case 'pull_image':
        // Pull an OCI image into containerd
        $sock = '/var/run/microvms/containerd.sock';
        $image = $_REQUEST['image'] ?? '';
        $pullNs = $_REQUEST['namespace'] ?? 'default';

        if (empty($image)) {
            echo json_encode(['success' => false, 'error' => 'No image reference provided']);
            break;
        }

        // Normalize image reference
        $ref = $image;
        // Add docker.io/ prefix if no registry specified
        if (!str_contains($ref, '/')) {
            $ref = "docker.io/library/$ref";
        } elseif (preg_match('#^docker\.io/([^/]+)$#', $ref, $m)) {
            // docker.io/nginx → docker.io/library/nginx
            $ref = "docker.io/library/" . $m[1];
        } elseif (!str_contains($ref, '.') && substr_count($ref, '/') === 1) {
            // user/image with no dots = Docker Hub
            $ref = "docker.io/$ref";
        }
        // Add :latest if no tag
        if (!str_contains($ref, ':')) $ref .= ':latest';

        // Validate namespace
        if (!preg_match('/^[a-z0-9\-]+$/', $pullNs)) {
            echo json_encode(['success' => false, 'error' => 'Invalid namespace']);
            break;
        }

        microvm_log("PULL_IMAGE: $ref into namespace $pullNs");

        // Ensure namespace exists
        exec("ctr -a $sock namespaces create " . escapeshellarg($pullNs) . " 2>/dev/null");

        // Pull image
        $cmd = "ctr -a " . escapeshellarg($sock) . " -n " . escapeshellarg($pullNs)
            . " images pull --platform linux/amd64 " . escapeshellarg($ref) . " 2>&1";
        exec($cmd, $output, $ret);
        $outputStr = implode("\n", $output);

        if ($ret === 0) {
            microvm_log("PULL_IMAGE OK: $ref");
            echo json_encode(['success' => true, 'message' => "Image pulled: $ref", 'output' => $outputStr]);
        } else {
            microvm_log("PULL_IMAGE FAILED: $ref (exit=$ret) $outputStr");
            echo json_encode(['success' => false, 'error' => "Pull failed (exit=$ret): $outputStr"]);
        }
        break;

    case 'pull_rootfs':
        // Pull image and create rootfs volume (thin snapshot or raw file)
        $sock = '/var/run/microvms/containerd.sock';
        $image = $_REQUEST['image'] ?? '';
        $pullNs = $_REQUEST['namespace'] ?? 'ch';
        $volName = preg_replace('/[^a-z0-9\-]/', '', strtolower($_REQUEST['name'] ?? ''));
        $storageType = $_REQUEST['storage_type'] ?? 'thin';

        if (empty($image) || empty($volName)) {
            echo json_encode(['success' => false, 'error' => 'Image reference and volume name required']);
            break;
        }

        // Normalize
        $ref = $image;
        if (!str_contains($ref, '/')) $ref = "docker.io/library/$ref";
        elseif (preg_match('#^docker\.io/([^/]+)$#', $ref, $m)) $ref = "docker.io/library/" . $m[1];
        if (!str_contains($ref, ':')) $ref .= ':latest';

        $cfg = microvm_load_config();
        $vmdir = $cfg['VMDIR'] ?? '/mnt/user/microvms';
        exec("ctr -a $sock namespaces create " . escapeshellarg($pullNs) . " 2>/dev/null");

        // Pull
        microvm_log("PULL_ROOTFS: $ref → $volName (ns=$pullNs, type=$storageType)");
        exec("ctr -a $sock -n " . escapeshellarg($pullNs) . " images pull --platform linux/amd64 " . escapeshellarg($ref) . " 2>&1", $pullOut, $pullRet);
        if ($pullRet !== 0) {
            echo json_encode(['success' => false, 'error' => 'Pull failed: ' . implode("\n", $pullOut)]);
            break;
        }

        if ($storageType === 'thin') {
            // Mount as writable thin snapshot
            $mountPoint = "/tmp/microvm-mount-$volName";
            exec("ctr -a $sock -n " . escapeshellarg($pullNs) . " images unmount " . escapeshellarg($mountPoint) . " 2>/dev/null");
            exec("ctr -a $sock -n " . escapeshellarg($pullNs) . " images mount --snapshotter devmapper --rw --platform linux/amd64 " . escapeshellarg($ref) . " " . escapeshellarg($mountPoint) . " 2>&1", $mountOut, $mountRet);
            if ($mountRet !== 0) {
                echo json_encode(['success' => false, 'error' => 'Thin mount failed: ' . implode("\n", $mountOut)]);
                break;
            }
            microvm_log("PULL_ROOTFS OK (thin): $volName mounted at $mountPoint");
            echo json_encode(['success' => true, 'message' => "Thin volume '$volName' created from $ref\nMounted at: $mountPoint"]);
        } else {
            // Raw: mount thin snapshot temporarily, copy to raw ext4 file
            $outDir = "$vmdir/$pullNs/$volName";
            @mkdir($outDir, 0755, true);
            $rawFile = "$outDir/rootfs.raw";
            $diskSize = 512; // MB default

            // Step 1: Mount image as thin snapshot (same as thin mode, temporary)
            $tmpSnap = "/tmp/microvm-mount-raw-$volName";
            exec("ctr -a $sock -n " . escapeshellarg($pullNs) . " images unmount " . escapeshellarg($tmpSnap) . " 2>/dev/null");
            exec("ctr -a $sock -n " . escapeshellarg($pullNs) . " images mount --snapshotter devmapper --rw --platform linux/amd64 " . escapeshellarg($ref) . " " . escapeshellarg($tmpSnap) . " 2>&1", $snapOut, $snapRet);
            if ($snapRet !== 0) {
                echo json_encode(['success' => false, 'error' => 'Failed to mount image snapshot: ' . implode("\n", $snapOut)]);
                break;
            }

            // Step 2: Create raw ext4 file, mount it, copy contents from snapshot
            exec("truncate -s {$diskSize}M " . escapeshellarg($rawFile));
            exec("mkfs.ext4 -q -F " . escapeshellarg($rawFile) . " 2>&1");
            $rawMount = "/tmp/microvm-rawmount-$volName";
            @mkdir($rawMount, 0755, true);
            exec("mount -o loop " . escapeshellarg($rawFile) . " " . escapeshellarg($rawMount) . " 2>&1", $mntOut, $mntRet);
            if ($mntRet !== 0) {
                exec("ctr -a $sock -n " . escapeshellarg($pullNs) . " images unmount " . escapeshellarg($tmpSnap) . " 2>/dev/null");
                echo json_encode(['success' => false, 'error' => 'Failed to mount raw image: ' . implode(' ', $mntOut)]);
                break;
            }

            // Step 3: Copy files from snapshot to raw (using cp -a for permissions)
            exec("cp -a " . escapeshellarg($tmpSnap) . "/. " . escapeshellarg($rawMount) . "/ 2>&1", $cpOut, $cpRet);

            // Step 4: Cleanup mounts
            exec("umount " . escapeshellarg($rawMount) . " 2>/dev/null");
            @rmdir($rawMount);
            exec("ctr -a $sock -n " . escapeshellarg($pullNs) . " images unmount " . escapeshellarg($tmpSnap) . " 2>/dev/null");

            if ($cpRet !== 0) {
                echo json_encode(['success' => false, 'error' => 'Copy failed: ' . implode("\n", $cpOut)]);
                break;
            }

            $size = filesize($rawFile);
            microvm_log("PULL_ROOTFS OK (raw): $rawFile (" . round($size/1048576) . " MB)");
            echo json_encode(['success' => true, 'message' => "Raw rootfs created: $rawFile (" . round($size/1048576) . " MB)"]);
        }
        break;

    case 'export_rootfs':
        // Export thin volume to raw file (dd from devmapper device)
        $sock = '/var/run/microvms/containerd.sock';
        $volName = preg_replace('/[^a-z0-9\-]/', '', strtolower($_REQUEST['name'] ?? ''));
        $ns = $_REQUEST['namespace'] ?? 'ch';
        $cfg = microvm_load_config();
        $vmdir = $cfg['VMDIR'] ?? '/mnt/user/microvms';

        if (empty($volName)) {
            echo json_encode(['success' => false, 'error' => 'Volume name required']);
            break;
        }

        // Find the devmapper device for this volume
        $snapshotKey = "/tmp/microvm-mount-$volName";
        $mounts = trim(shell_exec("ctr -a $sock -n " . escapeshellarg($ns) . " snapshots --snapshotter devmapper mounts /tmp " . escapeshellarg($snapshotKey) . " 2>/dev/null"));
        $device = '';
        if (preg_match('#/dev/mapper/\S+#', $mounts, $m)) $device = $m[0];

        if (empty($device) || !file_exists($device)) {
            echo json_encode(['success' => false, 'error' => "No active thin device found for '$volName'"]);
            break;
        }

        // Export to raw file
        $outPath = "$vmdir/$ns/$volName";
        @mkdir($outPath, 0755, true);
        $rawFile = "$outPath/rootfs.raw";
        microvm_log("EXPORT_ROOTFS: $device → $rawFile");
        exec("dd if=" . escapeshellarg($device) . " of=" . escapeshellarg($rawFile) . " bs=4M status=none 2>&1", $ddOut, $ddRet);
        if ($ddRet !== 0) {
            echo json_encode(['success' => false, 'error' => 'dd failed: ' . implode("\n", $ddOut)]);
            break;
        }

        $size = filesize($rawFile);
        echo json_encode(['success' => true, 'message' => "Exported to $rawFile (" . round($size/1048576) . " MB)"]);
        break;

    case 'prune_images':
        $sock = '/var/run/microvms/containerd.sock';
        $output = [];
        $pruneNs = $_REQUEST['namespace'] ?? 'all';
        $cfg = microvm_load_config();
        $vmdir = $cfg['VMDIR'] ?? '/mnt/user/microvms';

        if ($pruneNs === 'all') {
            $nsList = trim(shell_exec("ctr -a $sock namespaces list -q 2>/dev/null"));
            $namespaces = array_filter(explode("\n", $nsList));
        } else {
            $namespaces = [trim($pruneNs)];
        }

        // Build set of images used by VMs (from config files)
        $usedImages = [];
        foreach (glob("$vmdir/*/*/*.json") as $f) {
            $content = @file_get_contents($f);
            if ($content && preg_match('/"ref"\s*:\s*"([^"]+)"/', $content, $m)) {
                $ref = str_replace('\\/', '/', $m[1]);
                // Normalize
                if (!str_contains($ref, '/')) $ref = "docker.io/library/$ref";
                elseif (preg_match('#^docker\.io/([^/]+)$#', $ref, $rm)) $ref = "docker.io/library/" . $rm[1];
                if (!str_contains($ref, ':')) $ref .= ':latest';
                $usedImages[$ref] = true;
            }
        }

        // Remove images not in usedImages
        $removed = 0;
        foreach ($namespaces as $ns) {
            $ns = trim($ns);
            if (empty($ns) || $ns === 'flintlock') continue;
            $imgList = shell_exec("ctr -a $sock -n $ns images ls -q 2>/dev/null");
            if (!$imgList) continue;
            foreach (array_filter(explode("\n", trim($imgList))) as $img) {
                if (!isset($usedImages[$img])) {
                    exec("ctr -a $sock -n $ns images rm " . escapeshellarg($img) . " 2>&1", $rmOut);
                    $output[] = "[$ns] Removed: $img";
                    $removed++;
                }
            }
        }
        echo json_encode(['success' => true, 'message' => $removed > 0 ? implode("\n", $output) : 'No unused images to prune (all images are used by VMs)']);
        break;

    case 'remove_image':
        // Remove a specific image by ref from a namespace
        $sock = '/var/run/microvms/containerd.sock';
        $ref = $_REQUEST['image'] ?? '';
        $ns = $_REQUEST['namespace'] ?? '';
        if (empty($ref) || empty($ns)) {
            echo json_encode(['success' => false, 'error' => 'Image reference and namespace required']);
            break;
        }
        exec("ctr -a $sock -n " . escapeshellarg($ns) . " images rm " . escapeshellarg($ref) . " 2>&1", $rmOut, $rmRet);
        if ($rmRet === 0) {
            microvm_log("REMOVE_IMAGE: $ref from $ns");
            echo json_encode(['success' => true, 'message' => "Removed: $ref from namespace $ns"]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Remove failed: ' . implode(' ', $rmOut)]);
        }
        break;

    case 'list_namespaces':
        $ctrSock = '/var/run/microvms/containerd.sock';
        $out = trim(shell_exec("ctr -a $ctrSock namespaces list -q 2>/dev/null"));
        $namespaces = array_filter(explode("\n", $out));
        echo json_encode(['success' => true, 'namespaces' => $namespaces]);
        break;

    case 'create_namespace':
        $nsName = $_REQUEST['namespace'] ?? '';
        if (!preg_match('/^[a-z0-9\-]+$/', $nsName)) {
            echo json_encode(['success' => false, 'error' => 'Invalid namespace name (lowercase, numbers, hyphens only)']);
            break;
        }
        if ($nsName === 'flintlock') {
            echo json_encode(['success' => false, 'error' => "'flintlock' is reserved for Liquidmetal orchestration"]);
            break;
        }
        $ctrSock = '/var/run/microvms/containerd.sock';
        exec("ctr -a $ctrSock namespaces create " . escapeshellarg($nsName) . " 2>&1", $out, $ret);
        if ($ret === 0) {
            echo json_encode(['success' => true, 'message' => "Namespace '$nsName' created"]);
        } else {
            echo json_encode(['success' => false, 'error' => implode(' ', $out)]);
        }
        break;

    case 'delete_namespace':
        $nsName = $_REQUEST['namespace'] ?? '';
        if (in_array($nsName, ['default', 'flintlock'])) {
            echo json_encode(['success' => false, 'error' => "'$nsName' cannot be deleted (protected)"]);
            break;
        }
        if (!preg_match('/^[a-z0-9\-]+$/', $nsName)) {
            echo json_encode(['success' => false, 'error' => 'Invalid namespace name']);
            break;
        }
        $ctrSock = '/var/run/microvms/containerd.sock';
        exec("ctr -a $ctrSock namespaces remove " . escapeshellarg($nsName) . " 2>&1", $out, $ret);
        if ($ret === 0) {
            echo json_encode(['success' => true, 'message' => "Namespace '$nsName' deleted"]);
        } else {
            echo json_encode(['success' => false, 'error' => implode(' ', $out)]);
        }
        break;

    case 'delete_rootfs':
        $rootfsPath = "$vmpath";
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

    case 'remove_volume':
        // Remove a volume: thin (unmount snapshot) or raw (delete file)
        $sock = '/var/run/microvms/containerd.sock';
        $volName = $_REQUEST['name'] ?? '';
        $volNs = $_REQUEST['namespace'] ?? 'default';
        $volType = $_REQUEST['type'] ?? 'thin';

        if (empty($volName)) {
            echo json_encode(['success' => false, 'error' => 'Volume name required']);
            break;
        }

        if ($volType === 'thin') {
            // Unmount the active snapshot
            exec("ctr -a $sock -n " . escapeshellarg($volNs) . " images unmount " . escapeshellarg($volName) . " 2>&1", $umOut, $umRet);
            // Also try removing the snapshot key
            exec("ctr -a $sock -n " . escapeshellarg($volNs) . " snapshots --snapshotter devmapper rm " . escapeshellarg($volName) . " 2>&1", $rmOut, $rmRet);
            if ($umRet === 0 || $rmRet === 0) {
                microvm_log("REMOVE_VOLUME (thin): $volName from $volNs");
                echo json_encode(['success' => true, 'message' => "Thin volume '$volName' removed from namespace $volNs"]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Remove failed: ' . implode(' ', array_merge($umOut, $rmOut))]);
            }
        } else {
            // Raw: delete the rootfs.raw file
            $cfg2 = microvm_load_config();
            $vd = $cfg2['VMDIR'] ?? '/mnt/user/microvms';
            $rawFile = "$vd/$volNs/$volName/rootfs.raw";
            if (file_exists($rawFile)) {
                unlink($rawFile);
                microvm_log("REMOVE_VOLUME (raw): $rawFile");
                echo json_encode(['success' => true, 'message' => "Raw volume removed: $rawFile"]);
            } else {
                echo json_encode(['success' => false, 'error' => "File not found: $rawFile"]);
            }
        }
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
        $configFile = microvm_find_config_file("$vmpath");
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
        // Determine VMM to choose input method
        $configFile = microvm_find_config_file("$vmpath");
        $vmm = $configFile ? microvm_get_vmm($configFile) : 'cloud-hypervisor';
        $escaped = str_replace("'", "'\\''", $input);

        if ($vmm === 'cloud-hypervisor') {
            // CH: write directly to PTY (bidirectional) - FIFO doesn't work with tail -f on named pipes
            $sock = "/tmp/microvms-{$name}.sock";
            $pty = trim(shell_exec("ch-remote --api-socket " . escapeshellarg($sock) . " info 2>/dev/null | grep -oP '/dev/pts/\\d+'"));
            if ($pty && file_exists($pty)) {
                exec("printf '%s\\n' '$escaped' > " . escapeshellarg($pty) . " 2>&1 &");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'No PTY available. Is the VM running?']);
            }
        } else {
            // FC: write to FIFO (piped into FC stdin)
            $fifo = "/tmp/microvms-{$name}.fifo";
            if (file_exists($fifo)) {
                exec("printf '%s\\n' '$escaped' > " . escapeshellarg($fifo) . " 2>&1 &");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'No FIFO available. Is the VM running?']);
            }
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
        // Manage containerd namespaces for VMM toggles
        $ctrSock = '/var/run/microvms/containerd.sock';
        if (file_exists($ctrSock)) {
            if ($key === 'CH_ENABLED' && $value === 'yes') {
                exec("ctr -a $ctrSock namespaces create ch 2>/dev/null");
            } elseif ($key === 'CH_ENABLED' && $value === 'no') {
                // Only remove if empty (no containers)
                $count = trim(shell_exec("ctr -a $ctrSock -n ch containers list -q 2>/dev/null | wc -l"));
                if ($count === '0') exec("ctr -a $ctrSock namespaces remove ch 2>/dev/null");
            } elseif ($key === 'FC_ENABLED' && $value === 'yes') {
                exec("ctr -a $ctrSock namespaces create fc 2>/dev/null");
            } elseif ($key === 'FC_ENABLED' && $value === 'no') {
                $count = trim(shell_exec("ctr -a $ctrSock -n fc containers list -q 2>/dev/null | wc -l"));
                if ($count === '0') exec("ctr -a $ctrSock namespaces remove fc 2>/dev/null");
            }
        }
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
