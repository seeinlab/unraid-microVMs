# microVMs Plugin Documentation

## Architecture & Design
- [Design Patterns](./design-patterns.md) — Directory structure, naming, storage, process architecture, config format
- [VM Config Schema](./vm-config-schema.json) — OpenAPI 3.0.3 schema for cloud-hypervisor.json / firecracker.json

## API Reference
- [Flintlockd gRPC API](./flintlockd-grpc-api.md) — CreateMicroVM, GetMicroVM, ListMicroVMs, DeleteMicroVM
- [Cloud Hypervisor API](./cloud-hypervisor-api.md) — CH v52 HTTP API (vm.info, vm.boot, vm.shutdown, etc.)
- [Firecracker API](./firecracker-api.md) — FC v1.16 REST API

## Research
- [Kata Containers](./kata-containers-research.md) — Evaluation as potential flintlockd replacement
- [Code Style Guide](./code-style-guide.md) — PHP/Bash coding conventions

## Progress
- [PROGRESS.md](./PROGRESS.md) — Version history, what's working, next steps
