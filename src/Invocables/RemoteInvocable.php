<?php

namespace Rushing\Popcorn\Invocables;

use Closure;
use InvalidArgumentException;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;

/**
 * An invocable answered out of process — an MCP tool or a webhook. The transport
 * is injected (`fn(string $name, array $input): array`) so this kernel depends on
 * no HTTP or MCP client; the host plugs whichever it uses. This is how a tenant
 * overrides a named capability with their own remote handler.
 */
class RemoteInvocable implements Invocable
{
    private Closure $transport;

    /**
     * @param  callable(string, array<string, mixed>): array<string, mixed>  $transport
     */
    public function __construct(
        private string $name,
        private Binding $binding,
        callable $transport,
        private ?string $endpoint = null,
    ) {
        if ($binding === Binding::Local) {
            throw new InvalidArgumentException('RemoteInvocable is for mcp/webhook bindings; use LocalInvocable for local.');
        }

        $this->transport = $transport(...);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function endpoint(): ?string
    {
        return $this->endpoint;
    }

    public function binding(): Binding
    {
        return $this->binding;
    }

    public function invoke(array $input): array
    {
        return ($this->transport)($this->name, $input);
    }
}
