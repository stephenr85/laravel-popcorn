<?php

namespace Rushing\Popcorn\Runner;

/**
 * Resource ceilings on a run — wall-time, cpu-time, memory. Honored by both substrates but
 * *enforced differently* (bwrap: systemd/rlimit around the process; wasm: native in-VM
 * `--fuel`/`max-memory-size`), so the {@see Result} normalizes the *observed* consumption and
 * metering stays backend-blind (popcorn-runner tickets 03, 06).
 *
 * A `null` on any axis means "unset" — the host/substrate default applies (no ceiling authored).
 */
class Limits
{
    public function __construct(
        public ?int $wallMs = null,
        public ?int $cpuMs = null,
        public ?int $memBytes = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            wallMs: isset($data['wallMs']) ? (int) $data['wallMs'] : null,
            cpuMs: isset($data['cpuMs']) ? (int) $data['cpuMs'] : null,
            memBytes: isset($data['memBytes']) ? (int) $data['memBytes'] : null,
        );
    }

    /**
     * The tighter of two ceilings on each axis — the intersection operator on the limits axis.
     * `null` (unbounded) yields to any concrete ceiling on the other side.
     */
    public function narrowest(self $other): self
    {
        return new self(
            wallMs: self::minAxis($this->wallMs, $other->wallMs),
            cpuMs: self::minAxis($this->cpuMs, $other->cpuMs),
            memBytes: self::minAxis($this->memBytes, $other->memBytes),
        );
    }

    private static function minAxis(?int $a, ?int $b): ?int
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return min($a, $b);
    }

    /** @return array<string, int|null> */
    public function toArray(): array
    {
        return [
            'wallMs' => $this->wallMs,
            'cpuMs' => $this->cpuMs,
            'memBytes' => $this->memBytes,
        ];
    }
}
