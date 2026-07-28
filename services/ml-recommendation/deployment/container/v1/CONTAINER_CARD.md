# Workeyx ML Recommendation Container v1

## Purpose

This package runs the frozen FastAPI `0.2.0` inference service and
`job-rec-inference-bundle-v1` as an internal-only, single-worker container.
It does not train, tune, evaluate, rebuild, or modify the Model or Bundle.

## Runtime contract

- Image: `workeyx/ml-recommendation:0.2.0-phase16`
- Base: Python `3.12.10-slim`, pinned by its multi-architecture index digest.
- Platform verified by Phase 16: `linux/amd64`.
- User and group: `10001:10001`.
- Workdir: `/app`.
- Port: `8100`.
- Writable location: `/tmp` only when the runtime supplies a tmpfs.
- Process: one Uvicorn worker, no reload, direct PID 1 signal handoff.
- Health: `/health/live` for process liveness and `/health/ready` for Bundle
  and token readiness.

## Included content

The runtime contains the installed inference package, the professional-domain
catalog and schema types required by the frozen Feature Pipeline, its exact dependency closure,
the two container scripts, and the eight frozen Bundle files under
`/app/data/bundles/recommendation/v1`. Synthetic generation, training, tuning,
evaluation, split, Dataset, test, cache, Git, Laravel, and local environment
artifacts are excluded.

## Security boundary

The service is intended for an internal network. A shared caller token is
required at runtime and is never a build argument, image environment default,
label, or manifest value. The recommended runtime is read-only, drops all
capabilities, enables `no-new-privileges`, and mounts a bounded `/tmp` tmpfs.

## Integrity

The deterministic machine-independent manifest in this directory records the
base digest, source revision, runtime versions, packaging-file hashes, and all
eight Bundle hashes. The Bundle loader verifies the embedded artifacts once
during application lifespan startup.

## Limitations

This phase does not provide a production deployment, Kubernetes resources,
CI/CD automation, a reverse proxy, multiple workers, a production load test,
or an SLA. Horizontal scaling should add stateless containers and monitoring.
The model is trained on synthetic data and must not make automatic hiring
decisions.
