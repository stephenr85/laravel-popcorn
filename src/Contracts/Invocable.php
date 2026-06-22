<?php

namespace Rushing\Popcorn\Contracts;

use Rushing\Popcorn\Binding;

/**
 * A named, transport-agnostic capability: array in, array out. The contract says
 * nothing about *where* the work happens — a {@see Binding} does. A schema
 * authored locally and one codegen'd remotely converge on this one read path.
 */
interface Invocable
{
    public function name(): string;

    public function binding(): Binding;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function invoke(array $input): array;
}
