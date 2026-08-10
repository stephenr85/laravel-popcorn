> You are in **rushing/laravel-popcorn** — a tiny kernel for transport-agnostic invocable capabilities and self-validating strategy ladders (local, MCP, or webhook bindings behind one named contract).

A leaf PHP/Laravel package: one `Invocable` contract (array in, array out), a
registry, a caching decorator, and a strategy ladder for capabilities with
multiple fallback attempts. See `CONTEXT.md` for the domain language and
`docs/adr/` for design decisions.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
