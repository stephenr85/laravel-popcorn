<?php

namespace Rushing\Popcorn\Invocables;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;

/**
 * A binding-agnostic caching decorator (ADR-0039): it wraps any {@see Invocable} and memoizes its
 * array-out result per a caller-supplied key derived from the array-in input. Because it *is* an
 * Invocable, caching is transparent to every caller and composes with any binding — a local handler,
 * an MCP tool, or a webhook are all cached the same way; "remote" is a Binding, "cached" is this
 * decorator. It reports the inner invocable's name + binding so the registry seam is unchanged.
 *
 * TTL semantics: a null TTL caches with no expiry (the `snapshot` scope — frozen for identical inputs);
 * a positive TTL expires the entry (the `invocation` scope — a memoized call shared across callers).
 */
class CachedInvocable implements Invocable
{
    private Closure $keyFor;

    /**
     * @param  callable(array<string, mixed>): string  $keyFor  derives a stable cache key from the input
     * @param  int|null  $ttl  seconds; null caches with no expiry
     */
    public function __construct(
        private Invocable $inner,
        private CacheRepository $cache,
        callable $keyFor,
        private ?int $ttl = null,
    ) {
        $this->keyFor = $keyFor(...);
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function binding(): Binding
    {
        return $this->inner->binding();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function invoke(array $input): array
    {
        $key = 'popcorn:cache:'.$this->inner->name().':'.($this->keyFor)($input);

        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->inner->invoke($input);

        $this->ttl === null
            ? $this->cache->forever($key, $result)
            : $this->cache->put($key, $result, $this->ttl);

        return $result;
    }
}
