<?php
// include/common.php - microVM Manager shared functions

define('MICROVM_PLUGIN', 'microvm.manager');
define('MICROVM_CFG_PATH', '/boot/config/plugins/' . MICROVM_PLUGIN . '/' . MICROVM_PLUGIN . '.cfg');

function microvm_load_config() {
    if (file_exists(MICROVM_CFG_PATH)) {
        return parse_ini_file(MICROVM_CFG_PATH) ?: [];
    }
    return [
        'SERVICE' => 'disable',
        'VMDIR' => '/mnt/user/appdata/microvm',
        'BRIDGE' => 'br0',
        'DEFAULT_CPUS' => '1',
        'DEFAULT_MEMORY' => '256',
        'AUTOSTART' => 'no',
    ];
}

function microvm_list_vms() {
    $cfg = microvm_load_config();
    $vmdir = $cfg['VMDIR'] ?? '/mnt/user/appdata/microvm';
    $vms = [];

    if (!is_dir($vmdir)) return $vms;

    foreach (glob("$vmdir/*/config.json") as $configFile) {
        $name = basename(dirname($configFile));
        $config = json_decode(file_get_contents($configFile), true);
        $sock = "/tmp/microvm-{$name}.sock";
        
        // Check if VM is running
        $running = false;
        if (file_exists($sock)) {
            $engine = $config['engine'] ?? 'cloud-hypervisor';
            if ($engine === 'firecracker') {
                // FC: check if process with this VM name is alive
                $running = !empty(trim(shell_exec("pgrep -f 'microvm-{$vm['name']}' 2>/dev/null")));
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
    return ['success' => ($ret === 0), 'output' => implode("\n", $output)];
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
    $vmdir = $cfg['VMDIR'] ?? '/mnt/user/appdata/microvm';
    $sock = "/tmp/microvm-{$name}.sock";
    $tag = $tag ?: date('Y-m-d_His');
    $snapdir = "$vmdir/$name/snapshots/$tag";

    // Detect engine from config.json
    $configFile = "$vmdir/$name/config.json";
    $engine = 'cloud-hypervisor';
    if (file_exists($configFile)) {
        $vmConfig = json_decode(file_get_contents($configFile), true);
        $engine = $vmConfig['engine'] ?? 'cloud-hypervisor';
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
    $vmdir = $cfg['VMDIR'] ?? '/mnt/user/appdata/microvm';
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
    $vmdir = $cfg['VMDIR'] ?? '/mnt/user/appdata/microvm';
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
    $vmdir = $cfg['VMDIR'] ?? '/mnt/user/appdata/microvm';
    $tag = basename($tag);
    $snapPath = "$vmdir/$name/snapshots/$tag";
    $sock = "/tmp/microvm-{$name}.sock";

    if (!is_dir($snapPath)) {
        return ['success' => false, 'error' => "Snapshot '$tag' not found for VM '$name'"];
    }

    // Detect engine
    $configFile = "$vmdir/$name/config.json";
    $vmConfig = [];
    if (file_exists($configFile)) {
        $vmConfig = json_decode(file_get_contents($configFile), true) ?: [];
    }
    $engine = $vmConfig['engine'] ?? 'cloud-hypervisor';

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

    $bridge = $vmConfig['bridge'] ?? ($cfg['BRIDGE'] ?? 'br0');
    $tap = "tap-{$name}";

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
        . " > /var/log/microvm-{$name}.log 2>&1 &";
    exec($cmd);
    sleep(2);

    // Verify VM came back
    exec("ch-remote --api-socket $sock ping 2>/dev/null", $verifyOut, $verifyRet);

    return [
        'success' => ($verifyRet === 0),
        'message' => ($verifyRet === 0)
            ? "VM '$name' restored from snapshot '$tag' and is running"
            : "Restore command issued but VM may not be responding yet. Check /var/log/microvm-{$name}.log",
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

    $bridge = $vmConfig['bridge'] ?? ($cfg['BRIDGE'] ?? 'br0');
    $tap = "tap-{$name}";

    // Ensure TAP device exists
    exec("ip link show $tap 2>/dev/null", $tapOut, $tapRet);
    if ($tapRet !== 0) {
        exec("ip tuntap add dev $tap mode tap 2>/dev/null");
        exec("ip link set $tap master $bridge 2>/dev/null");
        exec("ip link set $tap up 2>/dev/null");
    }

    // Start a new firecracker process (no boot config — we'll load from snapshot)
    $logFile = "/var/log/microvm-{$name}.log";
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
