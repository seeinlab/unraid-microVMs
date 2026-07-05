<?php
/*
 * microVMs for Unraid
 * Copyright (C) 2026
 * License: GPL-2.0 (consistent with Unraid plugin ecosystem)
 *
 * File: include/common.php
 * Description: Shared PHP functions for microVM lifecycle management.
 *              Provides helpers for listing, starting, stopping, resizing,
 *              snapshotting, and managing VMs across both Cloud Hypervisor
 *              and Firecracker VMMs.
 *
 * References:
 *   - Cloud Hypervisor API: docs/cloud-hypervisor-api.md
 *   - Firecracker API: docs/firecracker-api.md
 *   - Feature mapping: docs/feature-api-mapping.md
 */

// --- Constants ---
define('MICROVM_PLUGIN', 'microvms');
define('MICROVM_CFG_PATH', '/boot/config/plugins/' . MICROVM_PLUGIN . '/' . MICROVM_PLUGIN . '.controlplane.cfg');
define('MICROVM_RUNTIME', '/var/run/microvms');
define('MICROVM_SYSTEM', '/mnt/user/system/microvms');

function microvm_load_config() {
    if (file_exists(MICROVM_CFG_PATH)) {
        return parse_ini_file(MICROVM_CFG_PATH) ?: [];
    }
    return [
        'SERVICE' => 'disable',
        'VMDIR' => '/mnt/user/microvms',
        'BRIDGE' => 'br0',
        'DEFAULT_CPUS' => '1',
        'DEFAULT_MEMORY' => '256',
        'AUTOSTART' => 'no',
    ];
}

/**
 * Find the VM config file in a VM directory.
 * Looks for cloud-hypervisor.json or firecracker.json (new format),
 * falls back to config.json (legacy).
 *
 * @param string $vmPath Path to the VM directory
 * @return string|null Full path to config file, or null if not found
 */
function microvm_find_config_file($vmPath) {
    foreach (['cloud-hypervisor.json', 'firecracker.json'] as $candidate) {
        $path = "$vmPath/$candidate";
        if (file_exists($path)) return $path;
    }
    // Legacy fallback
    $legacy = "$vmPath/config.json";
    if (file_exists($legacy)) return $legacy;
    return null;
}

/**
 * Load and normalize a VM config from disk.
 * Handles both new nested format and legacy flat format.
 *
 * @param string $configFile Path to the config file
 * @return array|null Normalized config array, or null on failure
 */
function microvm_load_vm_config($configFile) {
    if (!file_exists($configFile)) return null;
    $config = json_decode(file_get_contents($configFile), true);
    if (!$config) return null;
    return $config;
}

/**
 * Get the VMM type from a config (new or legacy format).
 */
function microvm_get_vmm($config) {
    return $config['vmm'] ?? $config['engine'] ?? 'cloud-hypervisor';
}

/**
 * Get network config from a VM config (new or legacy format).
 */
function microvm_get_network($config) {
    if (isset($config['network'])) {
        return $config['network'];
    }
    // Legacy flat format
    return [
        'ip' => $config['ip'] ?? '',
        'gateway' => '192.168.50.1',
        'mac' => $config['mac'] ?? '',
        'bridge' => $config['bridge'] ?? 'br0',
        'tap_id' => $config['tap_id'] ?? 0,
    ];
}

/**
 * Get storage config from a VM config (new or legacy format).
 */
function microvm_get_storage($config) {
    if (isset($config['storage'])) {
        return $config['storage'];
    }
    // Legacy flat format
    return [
        'type' => $config['storage_type'] ?? 'raw',
        'size_mb' => $config['disk_size_mb'] ?? 500,
        'thin_device_id' => $config['thin_device_id'] ?? null,
    ];
}

/**
 * Get the log file path for a VM, organized by VMM subdirectory.
 * Returns: /var/log/microvms/{vmm}/{name}.log
 */
function microvm_get_log_path($name, $vmdir) {
    $vmPath = "$vmdir/$name";
    $configFile = microvm_find_config_file($vmPath);
    $vmm = 'cloud-hypervisor'; // default
    if ($configFile && file_exists($configFile)) {
        $config = json_decode(file_get_contents($configFile), true) ?: [];
        $vmm = microvm_get_vmm($config);
    }
    $dir = "/var/log/microvms/$vmm";
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return "$dir/$name.log";
}

function microvm_next_tap_id($vmdir) {
    $max_id = -1;
    if (is_dir($vmdir)) {
        foreach (glob("$vmdir/*/") as $vmPath) {
            $configFile = microvm_find_config_file(rtrim($vmPath, '/'));
            if (!$configFile) continue;
            $cfg = json_decode(file_get_contents($configFile), true);
            $network = microvm_get_network($cfg);
            $id = $network['tap_id'] ?? -1;
            if ($id > $max_id) $max_id = $id;
        }
    }
    return $max_id + 1;
}

function microvm_list_vms() {
    $cfg = microvm_load_config();
    $vmdir = $cfg['VMDIR'] ?? '/mnt/user/microvms';
    $vms = [];

    if (!is_dir($vmdir)) return $vms;

    foreach (glob("$vmdir/*/") as $vmPath) {
        $vmPath = rtrim($vmPath, '/');
        $name = basename($vmPath);
        $configFile = microvm_find_config_file($vmPath);
        if (!$configFile) continue;

        $config = json_decode(file_get_contents($configFile), true);
        if (!$config) continue;

        $sock = "/tmp/microvm-{$name}.sock";
        
        // Check if VM is running
        $running = false;
        if (file_exists($sock)) {
            $vmm = microvm_get_vmm($config);
            if ($vmm === 'firecracker') {
                // FC: check if process with this VM name is alive
                $running = !empty(trim(shell_exec("pgrep -f 'microvm-{$name}' 2>/dev/null")));
            } else {
                // CH: use ch-remote ping
                exec("ch-remote --api-socket $sock ping 2>/dev/null", $output, $ret);
                $running = ($ret === 0);
            }
        }

        $vms[] = [
            'name' => $name,
            'config' => $config,
            'state' => $running ? 'running' : 'stopped',
            'socket' => $sock,
        ];
    }

    return $vms;
}

function microvm_get_vm_info($name) {
    $sock = "/tmp/microvm-{$name}.sock";
    if (!file_exists($sock)) return null;
    
    $output = shell_exec("ch-remote --api-socket $sock info 2>/dev/null");
    if ($output) {
        return json_decode($output, true);
    }
    return null;
}

function microvm_start_vm($name) {
    exec("/etc/rc.d/rc.microvm start_vm " . escapeshellarg($name) . " 2>&1", $output, $ret);
    return ['success' => ($ret === 0), 'output' => implode("\n", $output)];
}

function microvm_stop_vm($name) {
    exec("/etc/rc.d/rc.microvm stop_vm " . escapeshellarg($name) . " 2>&1", $output, $ret);
    $outputStr = implode("\n", $output);
    if (strpos($outputStr, 'ACPI_TIMEOUT') !== false) {
        return ['success' => false, 'acpi_timeout' => true, 'output' => $outputStr];
    }
    return ['success' => ($ret === 0), 'output' => $outputStr];
}

function microvm_resize_vm($name, $cpus = null, $memory = null) {
    $sock = "/tmp/microvm-{$name}.sock";
    $results = [];
    
    if ($cpus) {
        exec("ch-remote --api-socket $sock resize --cpus " . intval($cpus) . " 2>&1", $out, $ret);
        $results['cpus'] = ($ret === 0);
    }
    if ($memory) {
        exec("ch-remote --api-socket $sock resize --memory " . intval($memory) . " 2>&1", $out, $ret);
        $results['memory'] = ($ret === 0);
    }
    
    return $results;
}

function microvm_snapshot_vm($name, $tag = null) {
    $cfg = microvm_load_config();
    $vmdir = $cfg['VMDIR'] ?? '/mnt/user/microvms';
    $sock = "/tmp/microvm-{$name}.sock";
    $tag = $tag ?: date('Y-m-d_His');
    $snapdir = "$vmdir/$name/snapshots/$tag";

    // Detect engine from config
    $configFile = microvm_find_config_file("$vmdir/$name");
    $engine = 'cloud-hypervisor';
    if ($configFile) {
        $vmConfig = json_decode(file_get_contents($configFile), true);
        $engine = microvm_get_vmm($vmConfig);
    }

    mkdir($snapdir, 0755, true);

    if ($engine === 'firecracker') {
        return microvm_snapshot_vm_fc($name, $sock, $snapdir);
    }

    // Cloud Hypervisor: Pause → snapshot → resume via ch-remote
    exec("ch-remote --api-socket $sock pause 2>&1");
    exec("ch-remote --api-socket $sock snapshot file://$snapdir 2>&1", $output, $ret);
    exec("ch-remote --api-socket $sock resume 2>&1");

    return ['success' => ($ret === 0), 'path' => $snapdir, 'output' => implode("\n", $output)];
}

/**
 * Firecracker snapshot: Pause → create snapshot → Resume via API socket
 */
function microvm_snapshot_vm_fc($name, $sock, $snapdir) {
    $snapshotFile = "$snapdir/snapshot";
    $memFile = "$snapdir/mem";

    // Step 1: Pause VM
    $result = microvm_fc_api_call($sock, '/vm', 'PATCH', ['state' => 'Paused']);
    if ($result['http_code'] !== 204) {
        return ['success' => false, 'error' => "Failed to pause FC VM (HTTP {$result['http_code']}): {$result['body']}"];
    }

    // Step 2: Create snapshot
    $result = microvm_fc_api_call($sock, '/snapshot/create', 'PUT', [
        'snapshot_type' => 'Full',
        'snapshot_path' => $snapshotFile,
        'mem_file_path' => $memFile,
    ]);
    if ($result['http_code'] !== 204) {
        // Try to resume even if snapshot failed
        microvm_fc_api_call($sock, '/vm', 'PATCH', ['state' => 'Resumed']);
        return ['success' => false, 'error' => "Failed to create FC snapshot (HTTP {$result['http_code']}): {$result['body']}"];
    }

    // Step 3: Resume VM
    $result = microvm_fc_api_call($sock, '/vm', 'PATCH', ['state' => 'Resumed']);
    if ($result['http_code'] !== 204) {
        return ['success' => false, 'error' => "Snapshot created but failed to resume VM (HTTP {$result['http_code']}): {$result['body']}"];
    }

    return ['success' => true, 'path' => $snapdir, 'output' => "Firecracker snapshot created: $snapshotFile + $memFile"];
}

/**
 * Make an API call to Firecracker via Unix socket using PHP curl.
 *
 * @param string $sock   Path to the Unix socket
 * @param string $path   API endpoint path (e.g. '/vm', '/snapshot/create')
 * @param string $method HTTP method (GET, PUT, PATCH, etc.)
 * @param array|null $body Request body (will be JSON-encoded)
 * @return array ['http_code' => int, 'body' => string]
 */
function microvm_fc_api_call($sock, $path, $method = 'GET', $body = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $sock);
    curl_setopt($ch, CURLOPT_URL, "http://localhost{$path}");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    } else {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    }

    $result = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($result === false) {
        return ['http_code' => 0, 'body' => "curl error: $error"];
    }

    return ['http_code' => $httpCode, 'body' => $result ?: ''];
}

function microvm_list_snapshots($name) {
    $cfg = microvm_load_config();
    $vmdir = $cfg['VMDIR'] ?? '/mnt/user/microvms';
    $snapdir = "$vmdir/$name/snapshots";
    $snapshots = [];

    if (!is_dir($snapdir)) return $snapshots;

    foreach (glob("$snapdir/*/") as $dir) {
        $tag = basename($dir);
        // Get directory modification time
        $mtime = filemtime($dir);
        // Calculate directory size
        $size = trim(shell_exec("du -sh " . escapeshellarg($dir) . " 2>/dev/null | cut -f1"));
        if (empty($size)) $size = '?';

        $snapshots[] = [
            'tag' => $tag,
            'date' => date('Y-m-d H:i:s', $mtime),
            'timestamp' => $mtime,
            'size' => $size,
            'path' => rtrim($dir, '/'),
        ];
    }

    // Sort by timestamp descending (newest first)
    usort($snapshots, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });

    return $snapshots;
}

function microvm_delete_snapshot($name, $tag) {
    $cfg = microvm_load_config();
    $vmdir = $cfg['VMDIR'] ?? '/mnt/user/microvms';
    // Sanitize tag to prevent directory traversal
    $tag = basename($tag);
    $snapPath = "$vmdir/$name/snapshots/$tag";

    if (!is_dir($snapPath)) {
        return ['success' => false, 'error' => "Snapshot '$tag' not found for VM '$name'"];
    }

    exec("rm -rf " . escapeshellarg($snapPath) . " 2>&1", $output, $ret);
    return [
        'success' => ($ret === 0),
        'message' => ($ret === 0) ? "Snapshot '$tag' deleted" : "Failed to delete snapshot: " . implode("\n", $output),
    ];
}

function microvm_restore_snapshot($name, $tag) {
    $cfg = microvm_load_config();
    $vmdir = $cfg['VMDIR'] ?? '/mnt/user/microvms';
    $tag = basename($tag);
    $snapPath = "$vmdir/$name/snapshots/$tag";
    $sock = "/tmp/microvm-{$name}.sock";

    if (!is_dir($snapPath)) {
        return ['success' => false, 'error' => "Snapshot '$tag' not found for VM '$name'"];
    }

    // Detect engine
    $configFile = microvm_find_config_file("$vmdir/$name");
    $vmConfig = [];
    if ($configFile) {
        $vmConfig = json_decode(file_get_contents($configFile), true) ?: [];
    }
    $engine = microvm_get_vmm($vmConfig);

    if ($engine === 'firecracker') {
        return microvm_restore_snapshot_fc($name, $tag, $snapPath, $sock, $vmConfig, $cfg);
    }

    // Cloud Hypervisor restore
    return microvm_restore_snapshot_ch($name, $tag, $snapPath, $sock, $vmConfig, $cfg);
}

/**
 * Restore a Cloud Hypervisor snapshot.
 */
function microvm_restore_snapshot_ch($name, $tag, $snapPath, $sock, $vmConfig, $cfg) {
    // Stop the VM if currently running
    if (file_exists($sock)) {
        exec("ch-remote --api-socket $sock ping 2>/dev/null", $pingOut, $pingRet);
        if ($pingRet === 0) {
            exec("ch-remote --api-socket $sock shutdown-vmm 2>/dev/null");
            sleep(2);
        }
        $pid = trim(shell_exec("pgrep -f 'microvm-{$name}' 2>/dev/null | head -1"));
        if ($pid) {
            exec("kill -9 $pid 2>/dev/null");
            sleep(1);
        }
        @unlink($sock);
    }

    $network = microvm_get_network($vmConfig);
    $bridge = $network['bridge'] ?? ($cfg['BRIDGE'] ?? 'br0');
    $tap_id = $network['tap_id'] ?? null;
    if ($tap_id === null) {
        return ['success' => false, 'error' => "No tap_id in config for VM '$name'"];
    }
    $tap = "tap{$tap_id}";

    // Ensure TAP device exists
    exec("ip link show $tap 2>/dev/null", $tapOut, $tapRet);
    if ($tapRet !== 0) {
        exec("ip tuntap add dev $tap mode tap 2>/dev/null");
        exec("ip link set $tap master $bridge 2>/dev/null");
        exec("ip link set $tap up 2>/dev/null");
    }

    // Restore from snapshot using cloud-hypervisor
    $cmd = "nohup cloud-hypervisor"
        . " --api-socket " . escapeshellarg($sock)
        . " --restore source_url=" . escapeshellarg("file://$snapPath")
        . " > /var/log/microvms/cloud-hypervisor/{$name}.log 2>&1 &";
    exec($cmd);
    sleep(2);

    // Verify VM came back
    exec("ch-remote --api-socket $sock ping 2>/dev/null", $verifyOut, $verifyRet);

    return [
        'success' => ($verifyRet === 0),
        'message' => ($verifyRet === 0)
            ? "VM '$name' restored from snapshot '$tag' and is running"
            : "Restore command issued but VM may not be responding yet. Check /var/log/microvms/{vmm}/{$name}.log",
    ];
}

/**
 * Restore a Firecracker snapshot.
 * 1. Kill existing FC process
 * 2. Start new firecracker process with fresh socket
 * 3. Load snapshot via PUT /snapshot/load
 * 4. Resume via PATCH /vm
 */
function microvm_restore_snapshot_fc($name, $tag, $snapPath, $sock, $vmConfig, $cfg) {
    $snapshotFile = "$snapPath/snapshot";
    $memFile = "$snapPath/mem";

    // Validate snapshot files exist
    if (!file_exists($snapshotFile) || !file_exists($memFile)) {
        return ['success' => false, 'error' => "Snapshot files missing: need '$snapPath/snapshot' and '$snapPath/mem'"];
    }

    // Kill existing FC process
    $pid = trim(shell_exec("pgrep -f 'microvm-{$name}' 2>/dev/null | head -1"));
    if ($pid) {
        exec("kill $pid 2>/dev/null");
        sleep(1);
        // Force kill if still alive
        $pidCheck = trim(shell_exec("pgrep -f 'microvm-{$name}' 2>/dev/null | head -1"));
        if ($pidCheck) {
            exec("kill -9 $pidCheck 2>/dev/null");
            sleep(1);
        }
    }
    @unlink($sock);

    $network = microvm_get_network($vmConfig);
    $bridge = $network['bridge'] ?? ($cfg['BRIDGE'] ?? 'br0');
    $tap_id = $network['tap_id'] ?? null;
    if ($tap_id === null) {
        return ['success' => false, 'error' => "No tap_id in config for VM '$name'"];
    }
    $tap = "tap{$tap_id}";

    // Ensure TAP device exists
    exec("ip link show $tap 2>/dev/null", $tapOut, $tapRet);
    if ($tapRet !== 0) {
        exec("ip tuntap add dev $tap mode tap 2>/dev/null");
        exec("ip link set $tap master $bridge 2>/dev/null");
        exec("ip link set $tap up 2>/dev/null");
    }

    // Start a new firecracker process (no boot config — we'll load from snapshot)
    $logFile = "/var/log/microvms/firecracker/{$name}.log";
    $cmd = "nohup firecracker --api-sock " . escapeshellarg($sock)
        . " --id " . escapeshellarg($name)
        . " > " . escapeshellarg($logFile) . " 2>&1 &";
    exec($cmd);

    // Wait for socket to appear
    $waited = 0;
    while (!file_exists($sock) && $waited < 5) {
        usleep(200000); // 200ms
        $waited++;
    }
    if (!file_exists($sock)) {
        return ['success' => false, 'error' => "Firecracker process failed to create socket at $sock. Check $logFile"];
    }

    // Load snapshot
    $result = microvm_fc_api_call($sock, '/snapshot/load', 'PUT', [
        'snapshot_path' => $snapshotFile,
        'mem_backend' => [
            'backend_path' => $memFile,
            'backend_type' => 'File',
        ],
        'enable_diff_snapshots' => false,
        'resume_vm' => true,
    ]);

    if ($result['http_code'] !== 204) {
        // Cleanup on failure
        $pid = trim(shell_exec("pgrep -f 'microvm-{$name}' 2>/dev/null | head -1"));
        if ($pid) exec("kill $pid 2>/dev/null");
        @unlink($sock);
        return ['success' => false, 'error' => "Failed to load FC snapshot (HTTP {$result['http_code']}): {$result['body']}"];
    }

    return [
        'success' => true,
        'message' => "VM '$name' restored from Firecracker snapshot '$tag' and is running",
    ];
}

// ============================================================================
// Flintlockd / gRPC Orchestrated Mode
// ============================================================================

define('FLINTLOCKD_ENDPOINT', 'localhost:9090');
define('FLINTLOCKD_SERVICE', 'microvm.services.api.v1alpha1.MicroVM');
define('GRPCURL_BIN', '/usr/local/bin/grpcurl');
define('FLINTLOCK_KERNEL_IMAGE', 'localhost:5050/kernel/ch:latest');
define('FLINTLOCK_NAMESPACE', 'default');

/**
 * Detect whether flintlockd orchestrated mode is active.
 * Returns true if:
 *   1. The config setting ORCHESTRATOR_MODE is 'flintlockd', OR
 *   2. Auto-detect: flintlockd is running and grpcurl binary exists
 */
/**
 * Check if Liquidmetal services (flintlockd + containerd) are running.
 * This does NOT affect UI flow — UI always uses direct mode.
 * Used only by the "Enable Liquidmetal" toggle and remote automation.
 *
 * @return bool True if flintlockd is running and reachable
 */
function microvm_is_flintlock_running() {
    if (!is_executable(GRPCURL_BIN)) return false;
    $pid = trim(shell_exec("pgrep -x flintlockd 2>/dev/null"));
    return !empty($pid);
}

/**
 * Execute a grpcurl command against flintlockd.
 *
 * @param string $method  gRPC method name (e.g. 'CreateMicroVM')
 * @param array  $payload Request payload as associative array
 * @return array ['success' => bool, 'data' => array|null, 'error' => string|null, 'raw' => string]
 */
function flintlock_grpc_call($method, $payload = []) {
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $cmd = sprintf(
        '%s -plaintext -d %s %s %s.%s 2>&1',
        GRPCURL_BIN,
        escapeshellarg($jsonPayload),
        FLINTLOCKD_ENDPOINT,
        FLINTLOCKD_SERVICE,
        $method
    );

    exec($cmd, $output, $ret);
    $raw = implode("\n", $output);

    if ($ret !== 0) {
        return [
            'success' => false,
            'data' => null,
            'error' => "grpcurl exited $ret: $raw",
            'raw' => $raw,
        ];
    }

    $data = json_decode($raw, true);
    return [
        'success' => true,
        'data' => $data,
        'error' => null,
        'raw' => $raw,
    ];
}

/**
 * Create a MicroVM via flintlockd gRPC (also starts it).
 *
 * @param string $name     VM identifier
 * @param int    $vcpu     Number of vCPUs
 * @param int    $memoryMb Memory in MB
 * @param string $ociImage OCI container image for rootfs
 * @param int    $diskMb   Root volume size in MB
 * @param string $namespace Flintlock namespace
 * @return array ['success' => bool, 'uid' => string|null, 'error' => string|null]
 */
function flintlock_create_vm($name, $vcpu = 1, $memoryMb = 1024, $ociImage = 'docker.io/library/alpine:3.18', $diskMb = 500, $namespace = null) {
    $namespace = $namespace ?? FLINTLOCK_NAMESPACE;

    $payload = [
        'microvm' => [
            'id' => $name,
            'namespace' => $namespace,
            'vcpu' => (int)$vcpu,
            'memory_in_mb' => (int)$memoryMb,
            'kernel' => [
                'image' => FLINTLOCK_KERNEL_IMAGE,
                'filename' => 'boot/vmlinux',
                'cmdline' => [
                    'console' => 'ttyS0',
                    'root' => '/dev/vda',
                    'rw' => '',
                    'reboot' => 'k',
                    'panic' => '1',
                ],
                'add_network_config' => true,
            ],
            'root_volume' => [
                'id' => 'rootvol',
                'is_read_only' => false,
                'source' => [
                    'container_source' => $ociImage,
                ],
                'size_in_mb' => (int)$diskMb,
            ],
            'interfaces' => [
                [
                    'device_id' => 'eth0',
                    'type' => 'TAP',
                ],
            ],
            'provider' => 'cloudhypervisor',
        ],
    ];

    $result = flintlock_grpc_call('CreateMicroVM', $payload);

    if (!$result['success']) {
        return ['success' => false, 'uid' => null, 'error' => $result['error']];
    }

    // Extract UID from response
    $uid = $result['data']['microvm']['spec']['uid'] ?? null;
    if (!$uid) {
        // Try alternate response paths
        $uid = $result['data']['microvm']['uid'] ?? ($result['data']['uid'] ?? null);
    }

    return [
        'success' => true,
        'uid' => $uid,
        'data' => $result['data'],
        'error' => null,
    ];
}

/**
 * List/get MicroVMs from flintlockd by namespace (optionally filter by name).
 *
 * @param string      $namespace Flintlock namespace
 * @param string|null $name      Optional VM name to filter
 * @return array ['success' => bool, 'vms' => array, 'error' => string|null]
 */
function flintlock_list_vms($namespace = null, $name = null) {
    $namespace = $namespace ?? FLINTLOCK_NAMESPACE;
    $payload = ['namespace' => $namespace];
    if ($name) {
        $payload['name'] = $name;
    }

    $result = flintlock_grpc_call('ListMicroVMs', $payload);

    if (!$result['success']) {
        return ['success' => false, 'vms' => [], 'error' => $result['error']];
    }

    $vms = $result['data']['microvm'] ?? [];
    return ['success' => true, 'vms' => $vms, 'error' => null];
}

/**
 * Get the status of a specific MicroVM via flintlockd.
 *
 * @param string $name      VM name/id
 * @param string $namespace Flintlock namespace
 * @return array ['success' => bool, 'state' => string, 'uid' => string|null, 'vm' => array|null]
 */
function flintlock_get_vm_status($name, $namespace = null) {
    $namespace = $namespace ?? FLINTLOCK_NAMESPACE;
    $result = flintlock_list_vms($namespace, $name);

    if (!$result['success']) {
        return ['success' => false, 'state' => 'unknown', 'uid' => null, 'vm' => null, 'error' => $result['error']];
    }

    if (empty($result['vms'])) {
        return ['success' => true, 'state' => 'not_found', 'uid' => null, 'vm' => null, 'error' => null];
    }

    // Find the matching VM
    $vm = $result['vms'][0] ?? null;
    if ($vm) {
        $uid = $vm['spec']['uid'] ?? ($vm['uid'] ?? null);
        $state = $vm['status']['state'] ?? 'unknown';
        return ['success' => true, 'state' => $state, 'uid' => $uid, 'vm' => $vm, 'error' => null];
    }

    return ['success' => true, 'state' => 'not_found', 'uid' => null, 'vm' => null, 'error' => null];
}

/**
 * Delete a MicroVM via flintlockd gRPC (shuts down then removes).
 *
 * @param string $uid The UID of the MicroVM to delete
 * @return array ['success' => bool, 'error' => string|null]
 */
function flintlock_delete_vm($uid) {
    if (empty($uid)) {
        return ['success' => false, 'error' => 'No UID provided for delete'];
    }

    $payload = ['uid' => $uid];
    $result = flintlock_grpc_call('DeleteMicroVM', $payload);

    return [
        'success' => $result['success'],
        'error' => $result['error'],
        'raw' => $result['raw'] ?? '',
    ];
}

/**
 * Stop a MicroVM via flintlockd. Since flintlockd doesn't support stop-without-delete,
 * this deletes the VM spec (which triggers graceful shutdown).
 *
 * @param string $name      VM name
 * @param string $namespace Flintlock namespace
 * @return array ['success' => bool, 'error' => string|null]
 */
function flintlock_stop_vm($name, $namespace = null) {
    $namespace = $namespace ?? FLINTLOCK_NAMESPACE;

    // First, get the UID for this VM
    $status = flintlock_get_vm_status($name, $namespace);
    if (!$status['success']) {
        return ['success' => false, 'error' => $status['error']];
    }
    if ($status['state'] === 'not_found') {
        return ['success' => true, 'error' => null]; // Already gone
    }

    $uid = $status['uid'];
    if (empty($uid)) {
        return ['success' => false, 'error' => "Could not determine UID for VM '$name'"];
    }

    return flintlock_delete_vm($uid);
}

/**
 * Save the flintlockd UID mapping for a VM to its config.json.
 * This allows correlating local VM names with flintlockd UIDs.
 */
function flintlock_save_uid($name, $uid) {
    $cfg = microvm_load_config();
    $vmdir = $cfg['VMDIR'] ?? '/mnt/user/microvms';
    $vmPath = "$vmdir/$name";
    $configFile = microvm_find_config_file($vmPath);

    if ($configFile) {
        $config = json_decode(file_get_contents($configFile), true) ?: [];
    } else {
        @mkdir($vmPath, 0755, true);
        $config = [];
        $configFile = "$vmPath/cloud-hypervisor.json";
    }

    $config['flintlock_uid'] = $uid;
    $config['flintlock_namespace'] = FLINTLOCK_NAMESPACE;
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
}

/**
 * Load the flintlockd UID for a VM from its config.json.
 *
 * @param string $name VM name
 * @return string|null The UID or null if not found
 */
function flintlock_load_uid($name) {
    $cfg = microvm_load_config();
    $vmdir = $cfg['VMDIR'] ?? '/mnt/user/microvms';
    $configFile = microvm_find_config_file("$vmdir/$name");

    if (!$configFile) return null;

    $config = json_decode(file_get_contents($configFile), true);
    return $config['flintlock_uid'] ?? null;
}

// ============================================================================
// End Flintlockd / gRPC Section
// ============================================================================

function microvm_pull_oci_image($image, $outputPath) {
    // Use crane to export OCI image as filesystem
    $tmpTar = "/tmp/microvm-oci-" . md5($image) . ".tar";
    exec("crane export " . escapeshellarg($image) . " " . escapeshellarg($tmpTar) . " 2>&1", $output, $ret);
    
    if ($ret !== 0) {
        return ['success' => false, 'error' => implode("\n", $output)];
    }
    
    // Create ext4 image and extract
    exec("dd if=/dev/zero of=$outputPath bs=1M count=500 2>/dev/null");
    exec("mkfs.ext4 -F $outputPath 2>/dev/null");
    exec("mkdir -p /tmp/microvm-mount && mount $outputPath /tmp/microvm-mount");
    exec("tar -xf $tmpTar -C /tmp/microvm-mount 2>&1");
    exec("umount /tmp/microvm-mount && rmdir /tmp/microvm-mount");
    unlink($tmpTar);
    
    return ['success' => true, 'path' => $outputPath];
}
