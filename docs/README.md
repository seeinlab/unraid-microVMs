# MicroVM Research & Testing Knowledge Base

## Index

1. [Architecture Overview](./architecture.md) - Stack decisions, comparisons
2. [Service Diagrams](./diagrams.md) - Mermaid diagrams: flow, structure, processes
3. [Cloud Hypervisor Reference](./cloud-hypervisor.md) - API, config, usage
4. [Unraid Integration](./unraid-integration.md) - Plugin system, rc scripts, persistence
5. [Test Results](./test-results.md) - All tests performed and outcomes
6. [Networking](./networking.md) - TAP, bridge, Docker networking options
7. [OCI Image Conversion](./oci-to-rootfs.md) - crane, podman export, rootfs creation
8. [Flintlock & Containerd](./flintlock-containerd.md) - Orchestration layer
9. [Plugin Development](./plugin-development.md) - How to build Unraid plugins

## Reference Plugins Analyzed
- [`unraid/unraid-tailscale`](https://github.com/unraid/unraid-tailscale) — Official daemon management (rc.d, watcher, OOP PHP)
- [`IkerSaint/ZFS-Master-Unraid`](https://github.com/IkerSaint/ZFS-Master-Unraid) — Nchan WebSocket, AJAX backend, SweetAlert2
- [`unraid/webgui` 7.2.7](https://github.com/unraid/webgui) — Core .page system, update.php, emcmd
