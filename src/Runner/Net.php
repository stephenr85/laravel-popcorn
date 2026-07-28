<?php

namespace Rushing\Popcorn\Runner;

/**
 * The network-egress rung a transform declares / is granted — a graduated ladder,
 * not a boolean (popcorn-runner ticket 05). At the *substrate* boundary it collapses
 * to a boolean (`--share-net` / `-S inherit-network`); the ladder lives at the
 * declaration + policy layer:
 *
 * - {@see Net::None}   — the deny-by-default floor; deterministic transforms.
 * - {@see Net::Scoped} — an optional refinement a transform offers when it happens to
 *   know its traffic. Unlocks no new capability; it *buys a lower trust bar*. Its
 *   destination-shape is deferred until a downstream egress proxy exists, so today it
 *   is honored at the substrate as "egress on".
 * - {@see Net::Open}   — unrestricted egress, a first-class (trust-gated) declaration
 *   for dynamically-wired external-service transforms that cannot enumerate destinations.
 */
enum Net: string
{
    case None = 'none';
    case Scoped = 'scoped';
    case Open = 'open';

    /** Whether the substrate must turn egress on (both `scoped` and `open`, until an egress proxy lands). */
    public function allowsEgress(): bool
    {
        return $this !== self::None;
    }

    /** Ladder rank, so `min()`/`intersect()` can narrow requested ∩ policy. */
    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Scoped => 1,
            self::Open => 2,
        };
    }

    /** The narrower (lower) of two rungs — the intersection operator on this axis. */
    public function narrowest(self $other): self
    {
        return $this->rank() <= $other->rank() ? $this : $other;
    }
}
