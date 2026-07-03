# Service Architecture Diagrams

## Comparison: Tailscale vs ZFS Master Patterns

| Aspect | Tailscale (Official) | ZFS Master (Community) |
|--------|---------------------|------------------------|
| Real-time updates | Watcher daemon + AJAX polling | Nchan WebSocket push |
| Service management | rc.d script + restart.sh | No daemon |
| PHP structure | OOP namespaced classes | Flat procedural |
| State tracking | JSON files + LocalAPI calls | Shell exec + parse |
| Frontend | jQuery AJAX `$.post()` | NchanSubscriber WebSocket |
| Packaging | Slackware .txz + binary .tgz | Single .tgz of folder |

**For MicroVM Manager: Use ZFS Master's nchan pattern** (WebSocket push is better UX for VM status that changes frequently), combined with Tailscale's rc.d service management pattern.

---

## 1. Plugin Installation Flow

```mermaid
sequenceDiagram
    participant User
    participant CA as Community Apps
    participant Unraid as Unraid (emhttpd)
    participant Flash as /boot (flash drive)
    participant RAM as /usr/local (RAM)
    participant Service as rc.microvm

    User->>CA: Install "MicroVM Manager"
    CA->>Unraid: Download microvm.manager.plg
    Unraid->>Flash: Save to /boot/config/plugins/microvm.manager.plg
    
    Note over Unraid: Execute PLG FILE elements
    Unraid->>Flash: Download cloud-hypervisor binary
    Unraid->>Flash: Download ch-remote binary
    Unraid->>Flash: Download microvm.manager.tgz
    
    Note over Unraid: Execute PLG install script
    Unraid->>RAM: Extract .tgz → /usr/local/emhttp/plugins/microvm.manager/
    Unraid->>RAM: cp binaries → /usr/local/bin/
    Unraid->>RAM: cp rc.microvm → /etc/rc.d/rc.microvm
    Unraid->>Flash: Create default microvm.manager.cfg
    Unraid->>Service: restart.sh → rc.microvm start

    Note over Unraid: On every reboot, PLG re-executes all above
```

## 2. Service Lifecycle

```mermaid
stateDiagram-v2
    [*] --> Installed: PLG install

    Installed --> Starting: rc.microvm start
    Starting --> WaitArray: Check /mnt/user exists
    WaitArray --> Starting: Not ready (sleep 2)
    WaitArray --> CreateTAPs: Array available
    CreateTAPs --> StartNchan: TAPs on br0
    StartNchan --> AutostartVMs: nchan daemon running
    AutostartVMs --> Running: VMs booted (if autostart=yes)
    StartNchan --> Running: No autostart

    Running --> Stopping: rc.microvm stop
    Stopping --> ACPIShutdown: Send power-button to all VMs
    ACPIShutdown --> WaitVMs: Wait max 30s
    WaitVMs --> ForceKill: Timeout
    WaitVMs --> CleanTAPs: VMs exited
    ForceKill --> CleanTAPs: kill processes
    CleanTAPs --> Stopped: Remove TAP interfaces

    Stopped --> Starting: rc.microvm start
    Stopped --> [*]: PLG remove

    Running --> Running: Start/Stop individual VMs
```

## 3. Folder Structure

```mermaid
graph TD
    subgraph "Flash Drive /boot (persistent)"
        FLASH["/boot/config/plugins/microvm.manager/"]
        FLASH --> CFG["microvm.manager.cfg<br/>(settings)"]
        FLASH --> BIN_CH["cloud-hypervisor-v52.0<br/>(5.5MB cached)"]
        FLASH --> BIN_CR["ch-remote-v52.0<br/>(1.7MB cached)"]
        FLASH --> TGZ["microvm.manager-VERSION.tgz<br/>(plugin package)"]
    end

    subgraph "RAM /usr/local (ephemeral, rebuilt each boot)"
        EMHTTP["/usr/local/emhttp/plugins/microvm.manager/"]
        EMHTTP --> PAGES["*.page files<br/>(WebGUI tabs)"]
        EMHTTP --> INCLUDE["include/<br/>common.php"]
        EMHTTP --> BACKEND["backend/<br/>MicroVMAdmin.php"]
        EMHTTP --> FRONTEND["frontend/<br/>MicroVM.js"]
        EMHTTP --> NCHAN["nchan/<br/>microvm_manager"]
        EMHTTP --> RESTART["restart.sh"]
        
        BINS["/usr/local/bin/"]
        BINS --> CH_BIN["cloud-hypervisor"]
        BINS --> CR_BIN["ch-remote"]
        
        RCD["/etc/rc.d/rc.microvm"]
    end

    subgraph "Array /mnt (persistent, available after array start)"
        VMDIR["/mnt/user/appdata/microvm/"]
        VMDIR --> KERNELS["kernels/<br/>vmlinux"]
        VMDIR --> VM0["vm-nginx/"]
        VM0 --> CONFIG["config.json"]
        VM0 --> ROOTFS["rootfs.raw"]
        VM0 --> SNAPS["snapshots/"]
        VMDIR --> VM1["vm-api/"]
        VMDIR --> VM2["vm-db/"]
    end

    subgraph "Runtime /tmp (ephemeral)"
        SOCKS["/tmp/microvm-*.sock"]
        PIDS["/var/run/microvm-manager.pid"]
        LOGS["/var/log/microvm-*.log"]
    end

    TGZ -.->|extract at boot| EMHTTP
    BIN_CH -.->|cp at boot| CH_BIN
    BIN_CR -.->|cp at boot| CR_BIN
```

## 4. Request Flow (User → VM Action)

```mermaid
sequenceDiagram
    participant Browser
    participant WebGUI as Unraid WebGUI (nginx)
    participant PHP as MicroVMAdmin.php
    participant RC as rc.microvm
    participant CH as cloud-hypervisor
    participant VM as MicroVM

    Note over Browser: User clicks "Start VM"
    Browser->>WebGUI: POST /plugins/microvm.manager/backend/MicroVMAdmin.php
    WebGUI->>PHP: {cmd: "start", name: "web"}
    PHP->>RC: exec("rc.microvm start_vm web")
    RC->>RC: Create TAP (if not exists)
    RC->>CH: cloud-hypervisor --api-socket /tmp/microvm-web.sock ...
    CH->>VM: Boot kernel + rootfs
    VM-->>CH: Running (init starts nginx)
    RC-->>PHP: exit code 0
    PHP-->>Browser: {"success": true}

    Note over Browser: User clicks "Resize"
    Browser->>WebGUI: POST /plugins/microvm.manager/backend/MicroVMAdmin.php
    WebGUI->>PHP: {cmd: "resize", name: "web", cpus: 4}
    PHP->>CH: exec("ch-remote --api-socket /tmp/microvm-web.sock resize --cpus 4")
    CH->>VM: Hot-add CPUs
    PHP-->>Browser: {"success": true}

    Note over Browser: User clicks "Stop"
    Browser->>WebGUI: POST /plugins/microvm.manager/backend/MicroVMAdmin.php
    WebGUI->>PHP: {cmd: "stop", name: "web"}
    PHP->>CH: exec("ch-remote --api-socket ... power-button")
    CH->>VM: ACPI power-button signal
    VM->>VM: init trap → nginx stop → poweroff
    VM-->>CH: Shutdown complete
    CH-->>CH: Process exits
    PHP-->>Browser: {"success": true}
```

## 5. Nchan Real-Time Updates (ZFS Master pattern)

```mermaid
sequenceDiagram
    participant Nchan as nchan/microvm_manager<br/>(PHP daemon)
    participant Redis as Unraid nchan (nginx)
    participant Browser as Browser (WebSocket)
    participant CH as cloud-hypervisor processes

    Note over Nchan: Infinite loop (every 5s)
    loop Every 5 seconds
        Nchan->>CH: ch-remote --api-socket /tmp/microvm-*.sock ping
        CH-->>Nchan: VM status (running/stopped)
        Nchan->>Redis: publish('microvm_manager', JSON status)
        Redis->>Browser: WebSocket push
        Browser->>Browser: Update UI (green/red indicators)
    end

    Note over Browser: Page load subscribes
    Browser->>Redis: NchanSubscriber('/sub/microvm_manager')
    Redis-->>Browser: Initial status
```

```javascript
// Frontend subscription (in .page file)
var microvm_sub = new NchanSubscriber('/sub/microvm_manager', {subscriber: 'websocket'});
microvm_sub.on('message', function(data) {
    var status = JSON.parse(data);
    updateVMTable(status);  // Update UI
});
microvm_sub.start();
```

## 6. Settings Page Flow

```mermaid
sequenceDiagram
    participant User
    participant Form as Settings Form
    participant Update as /update.php
    participant Flash as /boot/.../microvm.manager.cfg
    participant Restart as restart.sh
    participant RC as rc.microvm

    User->>Form: Change settings + click Apply
    Form->>Update: POST (#file, #command, form fields)
    Update->>Flash: Write key=value to .cfg file
    Update->>Restart: Execute restart.sh
    Restart->>Restart: "sleep 3; rc.microvm restart" | at now
    Restart-->>Update: returns immediately
    Update-->>Form: Show "Applied" in progressFrame

    Note over RC: 3 seconds later...
    RC->>RC: stop (shutdown VMs, remove TAPs)
    RC->>RC: start (create TAPs, start VMs with new config)
```

## 7. Boot Sequence

```mermaid
graph TD
    A[Unraid Boot] --> B[USB Flash mounted at /boot]
    B --> C[PLG files executed]
    C --> D[Extract plugin .tgz to RAM]
    D --> E[Copy binaries to /usr/local/bin]
    E --> F[Install rc.microvm to /etc/rc.d/]
    F --> G[Call restart.sh]
    G --> H{Array started?}
    H -->|No| I[rc.microvm waits for /mnt/user]
    H -->|Yes| J[rc.microvm start]
    I -->|Array starts| J
    J --> K[Read microvm.manager.cfg]
    K --> L{SERVICE=enable?}
    L -->|No| M[Exit - disabled]
    L -->|Yes| N[Create TAP interfaces]
    N --> O[Start nchan daemon]
    O --> P{AUTOSTART=yes?}
    P -->|No| Q[Ready - waiting for manual start]
    P -->|Yes| R[Boot VMs from config.json files]
    R --> Q
```

## 8. VM Data Flow (persistent vs ephemeral)

```mermaid
graph LR
    subgraph "Persistent (survives reboot)"
        A["config.json<br/>(VM definition)"]
        B["rootfs.raw<br/>(disk image)"]
        C["snapshots/<br/>(VM state)"]
        D["microvm.manager.cfg<br/>(global settings)"]
        E["kernels/vmlinux<br/>(shared kernel)"]
    end

    subgraph "Ephemeral (recreated each boot)"
        F["TAP devices<br/>(tap-vmname)"]
        G["CH processes<br/>(cloud-hypervisor)"]
        H["API sockets<br/>(/tmp/microvm-*.sock)"]
        I["PID files<br/>(/var/run/)"]
        J["Logs<br/>(/var/log/microvm-*)"]
    end

    A -->|read by| G
    B -->|mounted by| G
    E -->|loaded by| G
    G -->|creates| H
    F -->|used by| G
    D -->|read by rc.microvm| F
    
    style A fill:#90EE90
    style B fill:#90EE90
    style C fill:#90EE90
    style D fill:#90EE90
    style E fill:#90EE90
    style F fill:#FFB6C1
    style G fill:#FFB6C1
    style H fill:#FFB6C1
    style I fill:#FFB6C1
    style J fill:#FFB6C1
```

## 9. Comparison with Tailscale's Watcher Pattern

```mermaid
graph TB
    subgraph "Tailscale Pattern (AJAX Polling)"
        TS_W[tailscale-watcher.php<br/>Infinite loop] -->|calls| TS_API[tailscale CLI/LocalAPI]
        TS_API -->|state| TS_W
        TS_W -->|writes| TS_FILE[JSON state files]
        TS_BROWSER[Browser] -->|$.post every N sec| TS_DATA[data/Status.php]
        TS_DATA -->|reads| TS_FILE
        TS_DATA -->|returns| TS_BROWSER
    end

    subgraph "ZFS Master Pattern (Nchan WebSocket)"
        ZFS_N[nchan/zfs_master<br/>Infinite loop] -->|exec| ZFS_CMD[zfs/zpool commands]
        ZFS_CMD -->|output| ZFS_N
        ZFS_N -->|publish()| NCHAN[Unraid nchan/nginx]
        NCHAN -->|WebSocket push| ZFS_B[Browser]
    end

    subgraph "MicroVM Manager (Recommended: Nchan)"
        MVM_N[nchan/microvm_manager<br/>Infinite loop] -->|exec| MVM_CH[ch-remote ping/info]
        MVM_CH -->|status JSON| MVM_N
        MVM_N -->|publish()| MVM_NCHAN[Unraid nchan/nginx]
        MVM_NCHAN -->|WebSocket push| MVM_B[Browser]
    end
```

**Why Nchan for MicroVM Manager:**
- VMs change state frequently (boot/shutdown in seconds)
- WebSocket = instant UI update (no polling delay)
- ZFS Master proves it works well for this pattern
- Docker manager also uses nchan for container status
- Tailscale's polling pattern is fine for its use case (status rarely changes) but too slow for VMs
