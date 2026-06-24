# Popcorn

The dispatch kernel: transport-agnostic invocable capabilities and
self-validating strategy ladders. Popcorn names *what* a capability is and
*where* it is reached; it never embeds *how* a given handler does its work, nor
any HTTP/MCP/process client. Hosts plug those in.

## Language

**Invocable**:
A named capability with one read path — `array in → array out`. The contract
says nothing about where or how the work happens.
_Avoid_: handler, tool, command (those are mechanisms, not the capability)

**Binding**:
*Where* an Invocable is answered, relative to the network — never *how*.
`Local` is this machine (in-process PHP **or** a spawned subprocess); `Mcp` and
`Webhook` are over the network. Callers reason about locality (latency, auth,
partition); mechanism is the Invocable's own concern.
_Avoid_: transport, protocol (a Binding is coarser than either)

**LocalInvocable**:
An Invocable backed by an in-process PHP handler. Reports `Binding::Local`.

**ProcessInvocable**:
A `Binding::Local` Invocable answered by spawning a local OS subprocess (e.g.
Python), exchanging the payload as JSON over stdin/stdout. Owns the subprocess
concerns — binary path, timeout, non-zero exit, stderr — that distinguish it
from `LocalInvocable` while sharing its locality.

**RemoteInvocable**:
An Invocable answered over the network — `Binding::Mcp` or `Binding::Webhook`.
The transport is an injected closure, so the kernel depends on no HTTP or MCP
client.

**Strategy** / **StrategyLadder**:
An ordered set of attempts at a capability, each self-validating; the ladder
walks them until one produces an acceptable `StrategyResult`.

## Example

> **Dev:** The composition engine wants OTIO validation from Python. Is that a
> `Webhook` Binding?
> **Domain expert:** No — there's no remote service. It shells out to a local
> `python` process. That's `Local`: same machine, no network.
> **Dev:** But `LocalInvocable` is the in-process PHP one.
> **Domain expert:** Right — locality and mechanism are different axes. It's a
> `ProcessInvocable`: still `Binding::Local`, but the class owns the subprocess
> machinery. If we ever stand up a warm Python service, *then* it becomes a
> `RemoteInvocable` over `Mcp` — and the caller never changes.
