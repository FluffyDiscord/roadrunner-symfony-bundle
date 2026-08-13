# Upgrade guide

## v6 → v7

**Breaking:** `http.early_router_initialization` was removed, replaced by the
zero-config [worker warmup](README.md#worker-warmup).

- Remove `http.early_router_initialization` from
  `config/packages/fluffy_discord_road_runner.yaml` — the unknown key throws on boot.
- The boot-time dummy request is gone and `HttpWorker::DUMMY_REQUEST_ATTRIBUTE` no
  longer exists — delete any listener that checked it.
