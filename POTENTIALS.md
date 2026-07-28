# Popcorn potentials — one socket, many plugs

The whole point of popcorn is a single socket — the **`Invocable`** (named, array-in / array-out) —
behind which many plugs answer the same call without callers noticing. Bindings, decorators,
strategy-ladder rungs, and now **Runners** are all plugs behind that one socket (`the-seam-is-a-registry`).
There is deliberately **no `Binding::Sandbox`**: a sandbox is a mechanism, and mechanism is not a
socket (popcorn ADR-0001 — locality and mechanism are different axes).

This file catalogues what the socket can absorb, organized by the **trust ladder**, and names the
existing execution sites that should rehome onto it.

## The trust ladder — plugs, weakest boundary to strongest

| Rung | Plug | Boundary | Package |
|---|---|---|---|
| **local** | `LocalInvocable` | in-process PHP | kernel |
| **remote** | `RemoteInvocable` (`Mcp` / `Webhook`) | a network hop (someone else's kernel) | kernel |
| **process** | `ProcessInvocable` → `NullRunner` | a raw subprocess, **no isolation** | kernel (soft-deprecated) |
| **sandboxed** | `BwrapRunner` | OS namespaces on a shared host kernel | `laravel-popcorn-bubble` |
| **in-VM** | `WasmtimeRunner` | an in-process VM boundary | `laravel-popcorn-wasm` |
| *(future)* | Firecracker / gVisor Runner | a microVM | *(a further plug)* |

Every rung is the same `Invocable` to a caller; `CachedInvocable`, the `StrategyLadder`, and the
registry compose over all of them unchanged. Picking a rung is a **Grant profile + a Runner**, not a
new contract.

## Prior art in our own tree — we hand-rolled this seam twice

The Runner substrate is not speculative: the codebase reached for it before it existed.

- **`config/conduits.php`'s `sandbox_wrapper` + `StdioServerSpec::launchCommand()`** — a hand-rolled
  Runner-Grant precursor (a wrapper command + a launch argv), unenforced.
- **`ProcessInvocable`** itself — a subprocess launcher with a binary path + timeout and nothing else.

Both are "a launcher wrapping an entrypoint" — exactly what a `Runner` is, minus the Grant, the total
`Result`, and the isolation.

## Rehome order (candidate audit — map ticket 04)

Seven real execution sites; rehome in ascending churn:

1. **`ProcessOtioValidator`** (Python OTIO validator) — zero-churn: it *is* `ProcessInvocable`. Re-express as a `RunnerInvocable` over a `python` Manifest.
2. **`FluidSynthSynthesize`** (ffmpeg/ffprobe chain, a live `render_seconds` meter) — the metered standout; proves `meterQuantity(Result)` reads the output, not the telemetry.
3. **`StdioMcpTransport`** — retires the unenforced `sandbox_wrapper` gate onto a real `BwrapRunner` Grant.
4. **Browsershot / headless-Chromium** *(later)* — net-hungry / SSRF-adjacent; the candidate that first earns the `net:scoped` egress-proxy design.
5. **npx-vitest docs regen** *(later)*.
6. `command -v` probes + tenancy `->run()` — **leave alone** (not execution-of-authored-code).

Ordered rehome tickets live at `.scratch/popcorn-runner-rehome/`.

## Net-new advertised

- **Conduit RunnerTransforms** — sandboxed, user-authored transforms of another tool (ADR-0141).
- **Webhook body transforms** and **inter-tool data-shaping** — a transform wired pre/post any invocable.
- **music21 / tonal conformance** — the Python-under-bwrap groundwork stubbed at `CompositionServiceProvider.php:282`.
- **A published-transform directory** — the long-tail "app directory" of the make.com framing (past the current destination).

## Exposure posture (secret-sauce — map ticket 10)

- **Kernel + both substrates = fully-open `rushing/*` foundation.** No reserved capture; sandbox code
  *wants* an open, hardening boundary. The paywall falls **behind the canvas, not on it**.
- **The composed Conduit level = `splicewire/*` private-perpetual** (ADR-0141) — teased here only as a
  reach projection, never shipped in this package.
