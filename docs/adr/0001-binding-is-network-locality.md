# ADR-0001 — Binding is network-locality; subprocess capabilities are Local

Status: accepted
Date: 2026-06-24

## Context

An `Invocable` is `array in → array out` and says nothing about where the work
happens — a `Binding` does. The enum shipped with `Local`, `Mcp`, and `Webhook`.
The first concrete out-of-PHP need is OTIO validation: hand an `.otio` document
to the `opentimelineio` Python library and get back a temporal-coherence verdict
(`laravel-timeline-schema`'s `OtioValidator` seam). The question was whether a
"shell out to a local Python process" capability needed a new transport concept,
and whether to reach for an embedded-interpreter bridge (`swoole/phpy`) or a
subprocess wrapper package (`omaralalwi/laravel-py`) to do it.

## Decision

**`Binding` answers *where on the network*, not *how* the work is mechanically
performed.**

- `Local` — answered on **this machine**, by any mechanism: an in-process PHP
  handler *or* a spawned OS subprocess. No network, no auth, low latency.
- `Mcp` / `Webhook` — answered **over the network** by a remote handler.

A local Python call is therefore `Local`. The *how* — interpreter cold-start,
the binary must exist on the box, timeout, non-zero exit, stderr — is the
**Invocable implementation's** concern, not the Binding's. So a new
`ProcessInvocable` sits beside `LocalInvocable`: both report `binding() ===
Local`, but `ProcessInvocable` owns the subprocess machinery and passes the
payload as **JSON over stdin / JSON over stdout**. `LocalInvocable` stays the
pure in-process PHP handler.

We do **not** add a `Process`/`Command` binding case. Locality is the axis a
caller can reason about (latency, partition, auth); mechanism is not.

## Considered options

- **`swoole/phpy` (embedded CPython) — rejected.** It compiles CPython into the
  same process as Zend (a `.so` on every PHP host) and explicitly does not
  support Python `threading` or `asyncio`, which is what real Python AI serving
  relies on. It couples Python dependencies into PHP workers and fights the
  engine/host out-of-process seam (app ADR-0032). Genuinely fast, but the wrong
  layer for this codebase.
- **`omaralalwi/laravel-py` (subprocess wrapper) — rejected as superfluous.** It
  wraps `proc_open` and passes data through CLI args + env vars (hence its
  "never pass user-controlled input" warning and its `PATH`/`PYTHONPATH`
  blacklist). Laravel's first-party `Process` facade already covers timeouts,
  exit codes, and stderr with maintained code, and stdin-JSON sidesteps both the
  arg-length limit and the env-injection surface that package must police. An
  external dependency that wraps what the framework already wraps earns nothing.
- **HTTP/MCP sidecar as the default — deferred, not rejected.** A long-running
  FastAPI/MCP service is the right shape only when a workload needs a warm model
  resident, a GPU off the PHP box, or independent scaling. OTIO validation is a
  fast, stateless, CPU-bound function with none of those properties. Because the
  capability is named on the `Invocable` seam, the transport is swappable: if a
  future workload needs a warm service, its `ProcessInvocable` is replaced by a
  `RemoteInvocable` (`Mcp`/`Webhook`) without the caller, the engine, or the
  schema package noticing.

## Consequences

- `popcorn` gains `Invocables/ProcessInvocable` and depends on no Python bridge.
- A subprocess-backed capability is honest about its locality (`Local`) and
  honest about its mechanism (its class), instead of masquerading as a webhook.
- `laravel-timeline-schema` keeps shipping only the `OtioValidator` contract and
  the PHP-native `NullOtioValidator`; it still does not depend on popcorn. The
  Python-reaching binding (`ProcessInvocable('otio.validate', …)` bound to
  `OtioValidator`, off by default) is wired app-side, where popcorn lives.
