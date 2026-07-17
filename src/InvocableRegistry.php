<?php

namespace Rushing\Popcorn;

use InvalidArgumentException;
use Rushing\Popcorn\Contracts\Invocable;

/**
 * Resolves a capability name to whatever currently answers it. Registering a new
 * invocable under an existing name overrides it — the seam where a host swaps a
 * local default for a tenant's webhook without callers changing.
 */
class InvocableRegistry
{
    /** @var array<string, Invocable> */
    private array $invocables = [];

    public function register(Invocable $invocable): static
    {
        $this->invocables[$invocable->name()] = $invocable;

        return $this;
    }

    /**
     * Remove an invocable by name — the teardown half of a per-tenant overlay: a host
     * projects tenant-scoped invocables on a tenant switch and forgets them on revert,
     * so nothing bleeds across tenants on a shared worker. A no-op if absent.
     */
    public function forget(string $name): static
    {
        unset($this->invocables[$name]);

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->invocables[$name]);
    }

    public function get(string $name): Invocable
    {
        return $this->invocables[$name]
            ?? throw new InvalidArgumentException("No invocable registered for `{$name}`.");
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function invoke(string $name, array $input): array
    {
        return $this->get($name)->invoke($input);
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->invocables);
    }
}
