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
            exec("ch-remote --api-socket $sock ping 2>/dev/null", $output, $ret);
            $running = ($ret === 0);
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
    
    mkdir($snapdir, 0755, true);
    
    // Pause → snapshot → resume
    exec("ch-remote --api-socket $sock pause 2>&1");
    exec("ch-remote --api-socket $sock snapshot file://$snapdir 2>&1", $output, $ret);
    exec("ch-remote --api-socket $sock resume 2>&1");
    
    return ['success' => ($ret === 0), 'path' => $snapdir, 'output' => implode("\n", $output)];
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
