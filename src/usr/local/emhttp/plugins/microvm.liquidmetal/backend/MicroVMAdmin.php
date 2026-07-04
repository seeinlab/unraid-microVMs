<?php
/*
 * microVM Liquidmetal for Unraid
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
 * Orchestrator Modes:
 *   - 'direct': Uses rc.microvm + Cloud Hypervisor/Firecracker directly (default)
 *   - 'flintlockd': Uses grpcurl → flintlockd gRPC for VM lifecycle
 *   - 'auto': Auto-detects based on whether flintlockd is running
 *   Set via ORCHESTRATOR_MODE in plugin config (.cfg file)
 *
 * References:
 *   - docs/feature-api-mapping.md (UI ΓåÆ Backend ΓåÆ Engine API)
 *   - docs/cloud-hypervisor-api.md
 *   - docs/firecracker-api.md
 */
error_reporting(0); // Suppress warnings from mixing with JSON output

$plugin = "microvm.liquidmetal";
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once "$docroot/plugins/$plugin/include/common.php";

header('Content-Type: application/json');

// Log function
function microvm_log($msg) {
    $logfile = '/var/log/microvm-liquidmetal.log';
    $ts = date('Y-m-d H:i:s');
    file_put_contents($logfile, "[$ts] $msg\n", FILE_APPEND);
}

$cfg = microvm_load_config();
$vmdir = $cfg['VMDIR'] ?? '/mnt/user/microvms';
$bridge = $cfg['BRIDGE'] ?? 'br0';

$cmd = $_POST['cmd'] ?? '';
$name = $_POST['name'] ?? '';

switch ($cmd) {
    case 'list':
        $vms = microvm_list_vms();
        // If in flintlockd mode, enrich status from gRPC
        if (microvm_is_flintlock_mode()) {
            $flResult = flintlock_list_vms();
            if ($flResult['success'] && !empty($flResult['vms'])) {
                // Build lookup by VM id
                $flMap = [];
                foreach ($flResult['vms'] as $flVm) {
                    $id = $flVm['spec']['id'] ?? '';
                    if ($id) $flMap[$id] = $flVm;
                }
                // Merge flintlockd state into local VM list
                foreach ($vms as &$vm) {
                    $vmName = $vm['name'];
                    if (isset($flMap[$vmName])) {
                        $flState = $flMap[$vmName]['status']['state'] ?? 'unknown';
                        $vm['state'] = ($flState === 'CREATED' || $flState === 'RUNNING') ? 'running' : 'stopped';
                        $vm['flintlock_uid'] = $flMap[$vmName]['spec']['uid'] ?? null;
                        $vm['orchestrator'] = 'flintlockd';
                    }
                }
                unset($vm);
            }
        }
        echo json_encode($vms);
        break;

    case 'start':
        if (microvm_is_flintlock_mode()) {
            // Flintlockd: "start" means re-create the VM (flintlock doesn't support start of stopped VM)
            $configFile = "$vmdir/$name/config.json";
            if (!file_exists($configFile)) {
                echo json_encode(['success' => false, 'error' => "VM '$name' config not found"]);
                break;
            }
            $vmConfig = json_decode(file_get_contents($configFile), true);
            $vcpu = $vmConfig['boot_vcpus'] ?? ($cfg['DEFAULT_CPUS'] ?? 1);
            $memMb = $vmConfig['memory_mb'] ?? ($cfg['DEFAULT_MEMORY'] ?? 256);
            $ociImage = $vmConfig['oci_image'] ?? 'docker.io/library/alpine:3.18';
            $diskMb = $vmConfig['disk_size_mb'] ?? 500;

            // Check if already running in flintlockd
            $status = flintlock_get_vm_status($name);
            if ($status['state'] === 'CREATED' || $status['state'] === 'RUNNING') {
                echo json_encode(['success' => true, 'message' => "VM '$name' is already running (flintlockd)"]);
                break;
            }

            $result = flintlock_create_vm($name, $vcpu, $memMb, $ociImage, $diskMb);
            if ($result['success']) {
                flintlock_save_uid($name, $result['uid']);
                microvm_log("FLINTLOCK START (re-create): $name uid={$result['uid']}");
                echo json_encode(['success' => true, 'message' => "VM '$name' started via flintlockd", 'uid' => $result['uid'], 'orchestrator' => 'flintlockd']);
            } else {
                echo json_encode(['success' => false, 'error' => "Flintlockd create failed: " . $result['error']]);
            }
        } else {
            $result = microvm_start_vm($name);
            echo json_encode($result);
        }
        break;

    case 'stop':
        if (microvm_is_flintlock_mode()) {
            // Flintlockd DeleteMicroVM handles graceful shutdown internally:
            //   1. Calls vm.shutdown on CH API (graceful OS halt)
            //   2. Waits 30s for VM state == Shutdown
            //   3. Sends SIGHUP to CH process
            //   4. Waits for process exit
            $result = flintlock_stop_vm($name);
            if ($result['success']) {
                microvm_log("FLINTLOCK STOP: $name (DeleteMicroVM with graceful shutdown)");
                echo json_encode(['success' => true, 'message' => "VM '$name' stopped via flintlockd", 'orchestrator' => 'flintlockd']);
            } else {
                echo json_encode(['success' => false, 'error' => "Flintlockd stop failed: " . $result['error']]);
            }
        } else {
            $result = microvm_stop_vm($name);
            echo json_encode($result);
        }
        break;

    case 'force_stop':
        if (microvm_is_flintlock_mode()) {
            // Force: kill CH process directly, then clean up flintlockd spec
            $status = flintlock_get_vm_status($name);
            $uid = $status['uid'] ?? flintlock_load_uid($name);

            // Find CH process for this VM in flintlockd state dir
            $escapedName = preg_quote($name, '/');
            $pid = trim(shell_exec("pgrep -f 'flintlockd-state/vm/.*/{$escapedName}/' 2>/dev/null | head -1"));
            if ($pid) {
                exec("kill -9 $pid 2>&1");
                microvm_log("FLINTLOCK FORCE_STOP: killed PID $pid for $name");
            }

            // Kill any ttyd console relay
            $ttydPid = "/tmp/ttyd-microvm-{$name}.pid";
            if (file_exists($ttydPid)) {
                $tpid = trim(file_get_contents($ttydPid));
                if ($tpid) exec("kill $tpid 2>/dev/null");
                @unlink($ttydPid);
            }

            // Delete spec from flintlockd
            if ($uid) {
                flintlock_delete_vm($uid);
            }
            echo json_encode(['success' => true, 'message' => "Force killed VM '$name' (flintlockd)", 'orchestrator' => 'flintlockd']);
        } else {
            $sock = "/tmp/microvm-{$name}.sock";
            // Kill any ttyd console relay
            $ttydPid = "/tmp/ttyd-microvm-{$name}.pid";
            if (file_exists($ttydPid)) {
                $tpid = trim(file_get_contents($ttydPid));
                if ($tpid) exec("kill $tpid 2>/dev/null");
                @unlink($ttydPid);
            }
            // Kill the process - search for the socket path in cmdline
            $pid = trim(shell_exec("pgrep -f 'microvm-{$name}' 2>/dev/null | head -1"));
            if ($pid) {
                exec("kill -9 $pid 2>&1");
                sleep(1);
                @unlink($sock);
                echo json_encode(['success' => true, 'message' => "Force killed VM $name (PID: $pid)"]);
            } else {
                // Try removing stale socket
                @unlink($sock);
                echo json_encode(['success' => true, 'message' => "No process found, cleaned stale socket"]);
            }
        }
        break;

    case 'info':
        // If flintlockd mode, merge gRPC status
        if (microvm_is_flintlock_mode()) {
            $flStatus = flintlock_get_vm_status($name);
            $configFile = "$vmdir/$name/config.json";
            $localConfig = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
            $info = array_merge($localConfig, [
                'orchestrator' => 'flintlockd',
                'flintlock_state' => $flStatus['state'],
                'flintlock_uid' => $flStatus['uid'],
                'flintlock_vm' => $flStatus['vm'],
            ]);
            echo json_encode($info);
            break;
        }
        $info = microvm_get_vm_info($name);
        if ($info) {
            echo json_encode($info);
        } else {
            // Return the config file content
            $configFile = "$vmdir/$name/config.json";
            if (file_exists($configFile)) {
                echo file_get_contents($configFile);
            } else {
                echo json_encode(['error' => 'VM not found']);
            }
        }
        break;

    case 'status':
        // Dedicated status check — works in both modes
        if (microvm_is_flintlock_mode()) {
            $flStatus = flintlock_get_vm_status($name);
            echo json_encode([
                'success' => $flStatus['success'],
                'name' => $name,
                'state' => $flStatus['state'],
                'uid' => $flStatus['uid'],
                'orchestrator' => 'flintlockd',
                'error' => $flStatus['error'],
            ]);
        } else {
            $sock = "/tmp/microvm-{$name}.sock";
            $running = false;
            if (file_exists($sock)) {
                exec("ch-remote --api-socket $sock ping 2>/dev/null", $output, $ret);
                $running = ($ret === 0);
            }
            echo json_encode([
                'success' => true,
                'name' => $name,
                'state' => $running ? 'running' : 'stopped',
                'orchestrator' => 'direct',
            ]);
        }
        break;

    case 'resize':
        // Only Cloud Hypervisor supports live resize via ch-remote
        $configFile = "$vmdir/$name/config.json";
        $vmConfig = [];
        if (file_exists($configFile)) {
            $vmConfig = json_decode(file_get_contents($configFile), true);
            $vmEngine = $vmConfig['engine'] ?? 'cloud-hypervisor';
            if ($vmEngine === 'firecracker') {
                echo json_encode(['success' => false, 'error' => 'Live resize not supported for Firecracker engine. Recreate VM with new config.']);
                break;
            }
        }
        $cpus = $_POST['cpus'] ?? null;
        $memory = $_POST['memory'] ?? null;
        $result = microvm_resize_vm($name, $cpus, $memory);
        // Update config.json with new values if resize succeeded
        if (!empty($result['cpus']) && $cpus) {
            $vmConfig['boot_vcpus'] = intval($cpus);
        }
        if (!empty($result['memory']) && $memory) {
            $vmConfig['memory_mb'] = intval($memory) / 1048576; // bytes to MB
        }
        if (!empty($vmConfig)) {
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

        // --- Flintlockd mode: delete via gRPC first, then clean up local files ---
        if (microvm_is_flintlock_mode()) {
            // Check for snapshots - block delete if any exist
            $snapDir = "$vmPath/snapshots";
            if (is_dir($snapDir) && count(glob("$snapDir/*")) > 0) {
                $snapCount = count(glob("$snapDir/*"));
                echo json_encode(['success' => false, 'error' => "Cannot delete: VM has $snapCount snapshot(s). Remove snapshots first."]);
                break;
            }

            // Try to delete from flintlockd
            $uid = flintlock_load_uid($name);
            if ($uid) {
                $flResult = flintlock_delete_vm($uid);
                if (!$flResult['success']) {
                    microvm_log("FLINTLOCK DELETE warning: $name uid=$uid - " . ($flResult['error'] ?? 'unknown'));
                    // Continue to local cleanup even if flintlock delete fails (VM may already be gone)
                }
            } else {
                // Try to find by name
                $status = flintlock_get_vm_status($name);
                if ($status['uid']) {
                    flintlock_delete_vm($status['uid']);
                }
            }

            // Remove local VM folder
            if (is_dir($vmPath)) {
                exec("rm -rf " . escapeshellarg($vmPath) . " 2>&1", $output, $ret);
                $remaining = is_dir($vmPath) ? glob("$vmPath/*") : [];
                $deleted = ($ret === 0) || empty($remaining);
                microvm_log("FLINTLOCK DELETE: $name local cleanup " . ($deleted ? 'OK' : 'PARTIAL'));
                echo json_encode(['success' => $deleted, 'message' => "VM '$name' deleted (flintlockd + local).", 'orchestrator' => 'flintlockd']);
            } else {
                echo json_encode(['success' => true, 'message' => "VM '$name' deleted from flintlockd (no local folder).", 'orchestrator' => 'flintlockd']);
            }
            break;
        }

        // --- Direct mode (original code path) ---
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
        // Force kill if still running
        $pid = trim(shell_exec("pgrep -f 'microvm-{$name}' 2>/dev/null | head -1"));
        if ($pid) exec("kill -9 $pid 2>/dev/null");
        @unlink("/tmp/microvm-{$name}.sock");
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
        $ip = $_POST['ip'] ?? '';
        $gateway = $_POST['gateway'] ?? '192.168.50.1';
        $source = $_POST['source'] ?? 'oci';
        $ociImage = $_POST['oci_image'] ?? 'nginx:alpine';
        $diskSize = intval($_POST['disk_size'] ?? 500);
        $rootfsPath = $_POST['rootfs_path'] ?? '';
        $engine = $_POST['engine'] ?? 'cloud-hypervisor';

        // --- Flintlockd Orchestrated Mode ---
        if (microvm_is_flintlock_mode()) {
            // Normalize OCI image to full registry path
            $fullOciImage = $ociImage;
            if (strpos($ociImage, '/') === false) {
                $fullOciImage = "docker.io/library/$ociImage";
            } elseif (strpos($ociImage, '.') === false && strpos($ociImage, ':') === false) {
                $fullOciImage = "docker.io/$ociImage";
            }

            $result = flintlock_create_vm($name, $cpus, $memory, $fullOciImage, $diskSize);

            if ($result['success']) {
                // Save config locally for reference
                $vmPath = "$vmdir/$name";
                @mkdir($vmPath, 0755, true);
                $config = [
                    'name' => $name,
                    'engine' => $engine,
                    'boot_vcpus' => $cpus,
                    'memory_mb' => $memory,
                    'oci_image' => $fullOciImage,
                    'disk_size_mb' => $diskSize,
                    'ip' => $ip,
                    'bridge' => $bridge,
                    'autostart' => (($_POST['autostart'] ?? 'false') === 'true'),
                    'orchestrator' => 'flintlockd',
                    'flintlock_uid' => $result['uid'],
                    'flintlock_namespace' => FLINTLOCK_NAMESPACE,
                    'created_at' => date('c'),
                ];
                file_put_contents("$vmPath/config.json", json_encode($config, JSON_PRETTY_PRINT));

                microvm_log("FLINTLOCK CREATE: $name uid={$result['uid']}");
                echo json_encode([
                    'success' => true,
                    'message' => "VM '$name' created and started via flintlockd",
                    'started' => true,
                    'uid' => $result['uid'],
                    'orchestrator' => 'flintlockd',
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => "Flintlockd create failed: " . $result['error']]);
            }
            break;
        }

        // --- Direct Mode (original code path) ---
        $vmPath = "$vmdir/$name";
        $rootfs = "$vmPath/rootfs.raw";
        $systemDir = '/mnt/user/system/liquidmetal';
        $kernel = "$systemDir/$engine/kernels/vmlinux";

        // Create VM directory
        @mkdir($vmPath, 0755, true);

        // Generate MAC
        $mac = sprintf("52:54:00:%02x:%02x:%02x", rand(0,255), rand(0,255), rand(0,255));

        // Create rootfs
        if ($source === 'oci' && !empty($ociImage)) {
            // Pull OCI image and create rootfs
            microvm_log("Pulling OCI: $ociImage");
            $tmpTar = "/tmp/microvm-$name.tar";
            exec("crane export " . escapeshellarg($ociImage) . " " . escapeshellarg($tmpTar) . " 2>&1", $pullOutput, $pullRet);
            microvm_log("crane exit: $pullRet, output: " . implode(" ", $pullOutput));
            if ($pullRet !== 0) {
                echo json_encode(['success' => false, 'error' => 'Failed to pull image: ' . implode("\n", $pullOutput)]);
                break;
            }

            // Create ext4 image
            exec("dd if=/dev/zero of=$rootfs bs=1M count=$diskSize 2>/dev/null");
            exec("mkfs.ext4 -F $rootfs 2>/dev/null");
            exec("mkdir -p /tmp/microvm-mount-$name");
            exec("sync");
            $mountDev = $rootfs;
            foreach (["/mnt/cache", "/mnt/mtier", "/mnt/ztier", "/mnt/rtier"] as $base) {
                $candidate = $base . "/" . ltrim(str_replace("/mnt/user/", "", $rootfs), "/");
                if (file_exists($candidate)) { $mountDev = $candidate; break; }
            }
            exec("mount $mountDev /tmp/microvm-mount-$name");
            exec("tar -xf $tmpTar -C /tmp/microvm-mount-$name 2>&1");

            // Inject init script
            $initScript = <<<'INIT'
#!/bin/sh
mount -t proc proc /proc
mount -t sysfs sysfs /sys
mount -t devtmpfs devtmpfs /dev 2>/dev/null
mkdir -p /dev/pts && mount -t devpts devpts /dev/pts
for d in /sys/class/net/*; do n=$(basename $d); [ "$n" != "lo" ] && IFACE=$n && break; done
# Network configured via kernel cmdline ip= parameter
if command -v ip >/dev/null 2&1; then ip link set lo up; elif command -v ifconfig >/dev/null 2&1; then ifconfig lo up; fi
mkdir -p /run/nginx /var/log/nginx /var/lib/nginx/tmp
echo "nameserver 8.8.8.8" > /etc/resolv.conf
if [ -f /usr/sbin/nginx ]; then
  nginx -g 'daemon off;' &
elif [ -f /usr/bin/httpd ] || command -v httpd > /dev/null; then
  mkdir -p /var/www/html
  echo "<h1>MicroVM: HOSTNAME</h1>" > /var/www/html/index.html
  httpd -p 80 -h /var/www/html
else
  mkdir -p /var/www/html
  echo "<h1>MicroVM: HOSTNAME</h1>" > /var/www/html/index.html
  while true; do echo -e "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n$(cat /var/www/html/index.html)" | nc -l -p 80 -q1; done &
fi
trap 'kill $(jobs -p) 2>/dev/null; poweroff -f' TERM INT
echo "=== MicroVM Ready ==="
while true; do sleep 3600 & wait; done
INIT;
            $initScript = str_replace('HOSTNAME', $name, $initScript);
            file_put_contents("/tmp/microvm-mount-$name/init", $initScript);
            chmod("/tmp/microvm-mount-$name/init", 0755);

            exec("umount /tmp/microvm-mount-$name");
            exec("rmdir /tmp/microvm-mount-$name");
            @unlink($tmpTar);

        } elseif ($source === 'existing' && !empty($rootfsPath)) {
            $rootfs = $rootfsPath;
        }

        // Create config.json
        $config = [
            'name' => $name,
            'engine' => $engine,
            'kernel' => $kernel,
            'disk' => $rootfs,
            'cmdline' => "console=ttyS0 root=/dev/vda rw init=/init ip=$ip::$gateway:255.255.255.0:::off",
            'boot_vcpus' => $cpus,
            'max_vcpus' => $cpus * 2,
            'memory_mb' => $memory,
            'mac' => $mac,
            'ip' => $ip,
            'bridge' => $bridge,
            'tap_id' => microvm_next_tap_id($vmdir),
            'autostart' => (($_POST['autostart'] ?? 'false') === 'true'),
        ];
        file_put_contents("$vmPath/config.json", json_encode($config, JSON_PRETTY_PRINT));

        microvm_log("VM created: $name at $vmPath");
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
        $vmPath = "$vmdir/$name";
        @mkdir($vmPath, 0755, true);
        file_put_contents("$vmPath/config.json", json_encode($config, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'message' => "VM '$name' created from JSON."]);
        break;

    case 'console':
        // Serial console via ttyd + unix socket (proxied by Unraid nginx at /logterminal/)
        $logFile = "$vmdir/$name/vm.log";
        if (!file_exists($logFile)) $logFile = "/var/log/microvm-{$name}.log";
        $sock = "/tmp/microvm-{$name}.sock";

        // Check VM is running
        if (!file_exists($sock)) {
            echo json_encode(['success' => false, 'error' => "VM '$name' is not running"]);
            break;
        }

        // Detect engine
        $configFile = "$vmdir/$name/config.json";
        $vmConfig = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
        $engine = $vmConfig['engine'] ?? 'cloud-hypervisor';

        if ($engine !== 'cloud-hypervisor') {
            echo json_encode(['success' => false, 'error' => "Serial console not supported for Firecracker. Use Logs instead."]);
            break;
        }

        // Parse PTY path from CH log
        $ptyPath = null;
        if (file_exists($logFile)) {
            $logContent = file_get_contents($logFile);
            if (preg_match('/serial:\s*SerialConfig\s*\{[^}]*file:\s*Some\("(\/dev\/pts\/\d+)"\)/s', $logContent, $matches)) {
                $ptyPath = $matches[1];
            }
            if (!$ptyPath && preg_match('/serial.*?(?:file|pty)[:\s]*.*?(\/dev\/pts\/\d+)/si', $logContent, $matches2)) {
                $ptyPath = $matches2[1];
            }
        }

        if (!$ptyPath || !file_exists($ptyPath)) {
            echo json_encode([
                'success' => false,
                'error' => "No serial PTY found. Stop and Start the VM to enable serial console.",
            ]);
            break;
        }

        // Check ttyd
        $ttydBin = '/usr/local/bin/ttyd';
        if (!is_executable($ttydBin)) {
            echo json_encode(['success' => false, 'error' => 'ttyd not installed. Run: wget -qO /usr/local/bin/ttyd https://github.com/tsl0922/ttyd/releases/download/1.7.7/ttyd.x86_64 && chmod +x /usr/local/bin/ttyd']);
            break;
        }

        // Use unix socket proxied by Unraid's nginx at /logterminal/SOCKNAME/
        $sockName = "microvm-{$name}.console";
        $sockPath = "/var/tmp/{$sockName}.sock";
        $pidFile = "/var/tmp/ttyd-microvm-{$name}.pid";

        // Kill existing ttyd for this VM
        if (file_exists($pidFile)) {
            $oldPid = trim(file_get_contents($pidFile));
            if ($oldPid && file_exists("/proc/$oldPid")) {
                exec("kill $oldPid 2>/dev/null");
                usleep(500000);
            }
            @unlink($pidFile);
        }
        @unlink($sockPath);

        // Start ttyd on unix socket (Unraid's nginx proxies /logterminal/SOCK_NAME/ to it)
        $cmd = sprintf(
            'nohup %s -d0 -W -t rendererType=canvas -t closeOnDisconnect=true -t disableLeaveAlert=true ' .
            "-t 'theme={\"background\":\"black\"}' -t fontSize=15 -t fontFamily=monospace " .
            '-i %s /usr/local/bin/microvm-console %s > /dev/null 2>&1 & echo $!',
            $ttydBin,
            escapeshellarg($sockPath),
            escapeshellarg($ptyPath)
        );
        $pid = trim(shell_exec($cmd));
        if ($pid) {
            file_put_contents($pidFile, $pid);
        }

        usleep(500000); // wait for socket

        if (file_exists($sockPath)) {
            $url = "/logterminal/{$sockName}/";
            echo json_encode([
                'success' => true,
                'url' => $url,
                'pty' => $ptyPath,
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => "ttyd failed to create socket at $sockPath"]);
        }
        break;

    case 'console_stop':
        // Stop ttyd relay for a VM
        $pidFile = "/tmp/ttyd-microvm-{$name}.pid";
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
        if (!file_exists($logFile)) $logFile = "/var/log/microvm-{$name}.log";
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
            $logFile = "/var/log/microvm-{$name}.log";
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
        $pidFile = "/var/tmp/ttyd-microvm-{$name}-log.pid";

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
        $configFile = "$vmdir/$name/config.json";
        if (file_exists($configFile)) {
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
            exec("/etc/rc.d/rc.microvm $action 2>&1", $output, $ret);
            echo json_encode(['success' => ($ret === 0), 'message' => implode("\n", $output)]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
        break;

    case 'delete_rootfs':
        $rootfsPath = "$vmdir/$name";
        // Check VM is not running
        if (file_exists("/tmp/microvm-{$name}.sock")) {
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

        // Create ext4 image
        exec("dd if=/dev/zero of=$rootfs bs=1M count=$diskSize 2>/dev/null");
        exec("mkfs.ext4 -F $rootfs 2>/dev/null");
        exec("mkdir -p /tmp/microvm-mount-$pullName");
        exec("mount $rootfs /tmp/microvm-mount-$pullName");
        exec("tar -xf $tmpTar -C /tmp/microvm-mount-$pullName 2>&1");
        exec("umount /tmp/microvm-mount-$pullName");
        exec("rmdir /tmp/microvm-mount-$pullName 2>/dev/null");
        // Cleanup temp tar
        @unlink($tmpTar);

        microvm_log("PULL_ROOTFS: $image -> $rootfs ($diskSize MB)");
        echo json_encode(['success' => true, 'path' => $rootfs]);
        break;

    case 'view_log':
        $logfile = $_POST['logfile'] ?? '';
        // Only allow reading specific known log files
        $allowed = ['/var/log/flintlockd.log', '/var/log/flintlock-containerd.log'];
        if (!in_array($logfile, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Invalid log file']);
            break;
        }
        if (file_exists($logfile)) {
            $log = shell_exec("tail -100 " . escapeshellarg($logfile) . " 2>/dev/null");
            echo json_encode(['success' => true, 'log' => $log ?: '(empty)']);
        } else {
            echo json_encode(['success' => true, 'log' => '(log file does not exist yet)']);
        }
        break;

    case 'download_kernels':
        $dl_ch = ($_POST['cloud_hypervisor'] ?? '0') === '1';
        $dl_fc = ($_POST['firecracker'] ?? '0') === '1';
        $kernelDir = "/mnt/user/system/liquidmetal";
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

    default:
        echo json_encode(['error' => "Unknown command: $cmd"]);
}
