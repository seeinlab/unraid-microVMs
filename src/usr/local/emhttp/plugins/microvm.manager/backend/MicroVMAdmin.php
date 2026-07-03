<?php
// backend/MicroVMAdmin.php - AJAX command handler
error_reporting(0); // Suppress warnings from mixing with JSON output

$plugin = "microvm.manager";
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once "$docroot/plugins/$plugin/include/common.php";

header('Content-Type: application/json');

// Log function
function microvm_log($msg) {
    $logfile = '/var/log/microvm-manager.log';
    $ts = date('Y-m-d H:i:s');
    file_put_contents($logfile, "[$ts] $msg\n", FILE_APPEND);
}

$cfg = microvm_load_config();
$vmdir = $cfg['VMDIR'] ?? '/mnt/user/appdata/microvm';
$bridge = $cfg['BRIDGE'] ?? 'br0';

$cmd = $_POST['cmd'] ?? '';
$name = $_POST['name'] ?? '';

switch ($cmd) {
    case 'list':
        echo json_encode(microvm_list_vms());
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
        break;

    case 'info':
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

    case 'resize':
        // Only Cloud Hypervisor supports live resize via ch-remote
        $configFile = "$vmdir/$name/config.json";
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
        // Force kill if still running
        $pid = trim(shell_exec("pgrep -f 'microvm-{$name}' 2>/dev/null | head -1"));
        if ($pid) exec("kill -9 $pid 2>/dev/null");
        @unlink("/tmp/microvm-{$name}.sock");
        // Remove entire VM folder (config + rootfs)
        if (is_dir($vmPath)) {
            exec("rm -rf " . escapeshellarg($vmPath) . " 2>&1", $output, $ret);
            echo json_encode(['success' => ($ret === 0), 'message' => "VM '$name' deleted completely"]);
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

        $vmPath = "$vmdir/$name";
        $rootfs = "$vmPath/rootfs.raw";
        $kernel = "$vmdir/kernels/vmlinux";

        // Create VM directory
        @mkdir($vmPath, 0755, true);

        // Generate MAC
        $mac = sprintf("52:54:00:%02x:%02x:%02x", rand(0,255), rand(0,255), rand(0,255));
        $engine = $_POST['engine'] ?? 'cloud-hypervisor';

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
            exec("mount $rootfs /tmp/microvm-mount-$name");
            exec("tar -xf $tmpTar -C /tmp/microvm-mount-$name 2>&1");

            // Inject init script
            $initScript = <<<'INIT'
#!/bin/sh
mount -t proc proc /proc
mount -t sysfs sysfs /sys
mount -t devtmpfs devtmpfs /dev 2>/dev/null
mkdir -p /dev/pts && mount -t devpts devpts /dev/pts
for d in /sys/class/net/*; do n=$(basename $d); [ "$n" != "lo" ] && IFACE=$n && break; done
ip link set $IFACE up 2>/dev/null
ip link set lo up
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
            'autostart' => false,
        ];
        file_put_contents("$vmPath/config.json", json_encode($config, JSON_PRETTY_PRINT));

        microvm_log("VM created: $name at $vmPath");
        echo json_encode(['success' => true, 'message' => "VM '$name' created. Click Start to boot."]);
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

    default:
        echo json_encode(['error' => "Unknown command: $cmd"]);
}
