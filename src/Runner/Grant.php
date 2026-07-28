<?php

namespace Rushing\Popcorn\Runner;

use Rushing\Popcorn\Contracts\Runner;

/**
 * The **effective** capability grant — the only Grant a {@see Runner}
 * ever sees (popcorn-runner tickets 01, 05). Deny-by-default: {@see Grant::none()} is the floor
 * every axis starts from. The kernel *consumes* an effective Grant; it never sources one —
 * resolution (`effective = requested ∩ policy`) is host-side (`pm-resolver` stays host-side).
 *
 * A **superset DTO**, substrate-neutral by construction: `paths`/`net`/`env`/`limits` map onto
 * both bwrap flags and wasmtime flags; `seccompProfile` is the lone advisory field — honored by
 * the bubble Runner, a *declared no-op* on wasm (its job is subsumed by the wasm import boundary).
 * A Runner that cannot satisfy an axis must fail loud — silent-drop is banned (it would make the
 * in-band reflected Grant, the audit log, and the meter lie about what took effect).
 *
 * Immutable by convention (matches the package's no-`readonly` house style).
 */
class Grant
{
    /**
     * @param  list<string>  $pathsRo  absolute paths bound read-only into the sandbox
     * @param  list<string>  $pathsRw  absolute paths bound read-write into the sandbox
     * @param  array<string, string>  $env  an allowlist of env vars to set — never host passthrough
     * @param  ?string  $seccompProfile  bubble-only advisory profile name; a declared no-op on wasm
     */
    public function __construct(
        public array $pathsRo = [],
        public array $pathsRw = [],
        public Net $net = Net::None,
        public array $env = [],
        public Limits $limits = new Limits,
        public ?string $seccompProfile = null,
    ) {}

    /** The deny-by-default floor: no paths, no net, no env, no ceilings, no seccomp. */
    public static function none(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $paths = $data['paths'] ?? [];

        return new self(
            pathsRo: array_values(array_map('strval', (array) ($paths['ro'] ?? []))),
            pathsRw: array_values(array_map('strval', (array) ($paths['rw'] ?? []))),
            net: Net::from((string) ($data['net'] ?? 'none')),
            env: array_map('strval', (array) ($data['env'] ?? [])),
            limits: Limits::fromArray((array) ($data['limits'] ?? [])),
            seccompProfile: isset($data['seccompProfile']) ? (string) $data['seccompProfile'] : null,
        );
    }

    /**
     * Narrow this Grant by a ceiling — the pure `∩` operator behind `effective = requested ∩ policy`.
     * The host sources the ceiling (tenant trust policy); this is only the set-intersection math, so
     * it can never *widen* a grant. A transform can only ever narrow itself.
     */
    public function intersect(self $ceiling): self
    {
        return new self(
            pathsRo: array_values(array_intersect($this->pathsRo, $ceiling->pathsRo)),
            pathsRw: array_values(array_intersect($this->pathsRw, $ceiling->pathsRw)),
            net: $this->net->narrowest($ceiling->net),
            env: array_intersect_key($this->env, array_flip(array_keys($ceiling->env))),
            limits: $this->limits->narrowest($ceiling->limits),
            seccompProfile: $ceiling->seccompProfile === null ? null : $this->seccompProfile,
        );
    }

    /** Whether this grant asks for anything beyond the deny-all floor. */
    public function isFloor(): bool
    {
        return $this->pathsRo === []
            && $this->pathsRw === []
            && $this->net === Net::None
            && $this->env === []
            && $this->seccompProfile === null
            && $this->limits->wallMs === null
            && $this->limits->cpuMs === null
            && $this->limits->memBytes === null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'paths' => ['ro' => $this->pathsRo, 'rw' => $this->pathsRw],
            'net' => $this->net->value,
            'env' => $this->env,
            'limits' => $this->limits->toArray(),
            'seccompProfile' => $this->seccompProfile,
        ];
    }
}
