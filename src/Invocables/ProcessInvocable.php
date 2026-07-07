<?php

namespace Rushing\Popcorn\Invocables;

use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;

/**
 * A {@see Binding::Local} invocable answered by spawning a local OS subprocess
 * (e.g. a Python script). The payload crosses as JSON over stdin; the verdict
 * comes back as JSON over stdout. This class owns the subprocess concerns the
 * locality alone does not express — the binary path, a timeout, a non-zero exit,
 * and stderr — using Laravel's first-party {@see Process} facade so popcorn
 * pulls in no Python bridge or subprocess wrapper of its own.
 *
 * Locality and mechanism are different axes (popcorn ADR-0001): this still
 * reports `Binding::Local`, but unlike {@see LocalInvocable} it does its work out
 * of process. If a warm remote service is ever needed, the named capability is
 * rebound to a {@see RemoteInvocable} without the caller noticing.
 */
class ProcessInvocable implements Invocable
{
    /**
     * @param  list<string>  $command  The argv to spawn (e.g. ['python3', 'validate.py']).
     */
    public function __construct(
        private string $name,
        private array $command,
        private ?int $timeout = 60,
        private ?string $description = null,
    ) {}

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
        try {
            $payload = json_encode($input, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("popcorn: cannot encode input for '{$this->name}': {$e->getMessage()}", previous: $e);
        }

        $pending = Process::input($payload);

        if ($this->timeout !== null) {
            $pending = $pending->timeout($this->timeout);
        }

        $result = $pending->run($this->command);

        if ($result->failed()) {
            $stderr = trim($result->errorOutput()) ?: trim($result->output()) ?: 'no output';

            throw new RuntimeException("popcorn: subprocess '{$this->name}' failed (exit {$result->exitCode()}): {$stderr}");
        }

        try {
            $decoded = json_decode(trim($result->output()), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("popcorn: subprocess '{$this->name}' returned non-JSON stdout: {$e->getMessage()}", previous: $e);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("popcorn: subprocess '{$this->name}' must return a JSON object, got ".gettype($decoded));
        }

        return $decoded;
    }
}
