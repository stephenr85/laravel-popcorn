<?php

namespace Rushing\Popcorn\Invocables;

use Closure;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;

/** An invocable backed by a local PHP handler — the in-process default. */
final class LocalInvocable implements Invocable
{
    private Closure $handler;

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $handler
     */
    public function __construct(
        private readonly string $name,
        callable $handler,
        private readonly ?string $description = null,
    ) {
        $this->handler = $handler(...);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function binding(): Binding
    {
        return Binding::Local;
    }

    public function invoke(array $input): array
    {
        return ($this->handler)($input);
    }
}
