<?php

namespace Rushing\Popcorn\Runner;

use JsonException;
use Rushing\Popcorn\Runner\Exceptions\InvalidManifest;

/**
 * *What* to run — the immutable declaration a transform bundle carries (popcorn-runner ticket 05).
 *
 * **File = canon, VO = runtime, array = transport.** The canonical authoring/distribution surface
 * is a `popcorn.json` file colocated with the entrypoint; the kernel loads + validates it into this
 * immutable value object at registration time. Static and immutable per transform *version* —
 * nothing per-invocation lives here (all per-call variation rides `array $input` + the host-resolved
 * {@see Grant}).
 *
 * **One shape, optional fields.** The load-bearing *core* the substrate needs to build the argv
 * itself (`name`, `runtime`, `entrypoint`, `files`) is always present; the publishing metadata a
 * simple internal transform omits (`input`/`output` schema for tool→transform typechecking, and the
 * `requests` grant block) is optional. The substrate-specific transport/build metadata
 * (`io`, `build`, `link`, `engineVersion`) defaults so the basic subprocess path is untaxed.
 *
 * Language lives *here*, not in the Runner type — one `BwrapRunner` runs Node or Python via argv
 * (ticket 01: a substrate is one Runner per isolation-backend, never per language).
 */
class Manifest
{
    /**
     * @param  string  $runtime  e.g. `python@3.12`, `node@22`, `javy`, `python-wasi` — a flat id the host factory routes to a substrate
     * @param  list<string>  $files  paths (relative to the bundle root) bound read-only into the sandbox
     * @param  ?array<string, mixed>  $inputSchema  JSON-schema for the input (optional; enables tool→transform typechecking)
     * @param  ?array<string, mixed>  $outputSchema  JSON-schema for the output (optional)
     * @param  ?GrantRequest  $requests  the requested grant — an honest upper bound; omitted ⇒ the transform asks for the deny-all floor
     * @param  ?Build  $build  wasm-only: whether the bundle ships a runnable `.wasm` or source to compile-and-cache
     * @param  ?Link  $link  wasm/Javy-only linking mode
     * @param  ?string  $engineVersion  wasm/Javy dynamic-prebuilt only: the engine the user module was compiled against
     */
    public function __construct(
        public string $name,
        public string $runtime,
        public string $entrypoint,
        public array $files = [],
        public Io $io = Io::Stdio,
        public ?array $inputSchema = null,
        public ?array $outputSchema = null,
        public ?GrantRequest $requests = null,
        public ?Build $build = null,
        public ?Link $link = null,
        public ?string $engineVersion = null,
        public ?string $description = null,
        public ?string $bundleRoot = null,
    ) {}

    /**
     * The bundle root — the host directory holding `entrypoint` + `files`, which a substrate binds
     * into the sandbox (e.g. bubble's `--ro-bind $bundleRoot /pkg`). "The file *is* the bundle root"
     * (ticket 05): {@see Manifest::fromFile()} sets it to the manifest's own directory. When a
     * Manifest is built from an array (e.g. derived from a DB-stored transform), the host stages the
     * files and stamps the root with {@see Manifest::withBundleRoot()}.
     */
    public function withBundleRoot(string $bundleRoot): self
    {
        $clone = clone $this;
        $clone->bundleRoot = $bundleRoot;

        return $clone;
    }

    /** The absolute host path to the entrypoint (bundleRoot-joined when relative), for the NullRunner / degrade path. */
    public function entrypointPath(): string
    {
        if ($this->bundleRoot === null || str_starts_with($this->entrypoint, '/')) {
            return $this->entrypoint;
        }

        return rtrim($this->bundleRoot, '/').'/'.$this->entrypoint;
    }

    /**
     * Load + validate a `popcorn.json` file into the runtime VO. The `entrypoint`/`files` are
     * interpreted relative to the file's own directory unless already absolute — the file *is* the
     * bundle root.
     *
     * @throws InvalidManifest
     */
    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidManifest("popcorn: manifest file not found at `{$path}`.");
        }

        $raw = (string) file_get_contents($path);

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidManifest("popcorn: manifest at `{$path}` is not valid JSON: {$e->getMessage()}", previous: $e);
        }

        if (! is_array($decoded)) {
            throw new InvalidManifest("popcorn: manifest at `{$path}` must decode to a JSON object.");
        }

        return self::fromArray($decoded)->withBundleRoot(dirname($path));
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidManifest
     */
    public static function fromArray(array $data): self
    {
        foreach (['name', 'runtime', 'entrypoint'] as $required) {
            if (! isset($data[$required]) || $data[$required] === '') {
                throw new InvalidManifest("popcorn: manifest is missing required field `{$required}`.");
            }
        }

        return new self(
            name: (string) $data['name'],
            runtime: (string) $data['runtime'],
            entrypoint: (string) $data['entrypoint'],
            files: array_values(array_map('strval', (array) ($data['files'] ?? []))),
            io: self::enumOrDefault(Io::class, $data['io'] ?? null, Io::Stdio),
            inputSchema: isset($data['input']) ? (array) $data['input'] : null,
            outputSchema: isset($data['output']) ? (array) $data['output'] : null,
            requests: isset($data['requests']) ? GrantRequest::fromArray((array) $data['requests']) : null,
            build: self::nullableEnum(Build::class, $data['build'] ?? null),
            link: self::nullableEnum(Link::class, $data['link'] ?? null),
            engineVersion: isset($data['engineVersion']) ? (string) $data['engineVersion'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
        );
    }

    /** The requested grant, or the deny-all floor if the transform declared none. */
    public function requestedGrant(): Grant
    {
        return $this->requests?->grant ?? Grant::none();
    }

    /**
     * @param  class-string  $enum
     */
    private static function nullableEnum(string $enum, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $case = $enum::tryFrom((string) $value);

        if ($case === null) {
            throw new InvalidManifest("popcorn: manifest has an unknown `{$enum}` value `{$value}`.");
        }

        return $case;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @param  T  $default
     * @return T
     */
    private static function enumOrDefault(string $enum, mixed $value, mixed $default): mixed
    {
        return self::nullableEnum($enum, $value) ?? $default;
    }
}
