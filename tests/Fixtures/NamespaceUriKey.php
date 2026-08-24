<?php

namespace Rushing\Popcorn\Tests\Fixtures;

use Rushing\Popcorn\Registries\RegistryKey;

/**
 * A FOREIGN {@see RegistryKey}, standing in for `schemastud/laravel-json-ns` — which keys by namespace
 * URI because URI-is-identity is that package's whole thesis.
 *
 * A deliberate twin of `php-popcorn`'s `tests/Unit/Registries/Fixtures/NamespaceUriKey.php` rather than
 * a shared class: the kernel's test namespace is not autoloaded here, and the alternative — promoting a
 * test fixture into `src/` so a second package can use it — would put a foreign key type in the shipped
 * surface of the package whose whole claim is that it never needs one.
 *
 * It exists because of ticket 11's standing rule: a green suite against {@see \Rushing\Popcorn\Registries\Key}
 * proves nothing about the {@see RegistryKey} seam, because `Key` is the one implementation that
 * round-trips through its own rendering. Three defects of exactly that shape have shipped into the
 * kernel already.
 */
class NamespaceUriKey implements RegistryKey
{
    /** @param  list<string>  $segments */
    private function __construct(private array $segments, private string $uri) {}

    /** `https://schemastud.dev/ns/grounding/2` → the stem, then the pin as its child. */
    public static function of(string $uri): self
    {
        if (preg_match('#^(.*)/(\d+)$#', $uri, $matched) === 1) {
            return new self([$matched[1], $matched[2]], $uri);
        }

        return new self([$uri], $uri);
    }

    public function segments(): array
    {
        return $this->segments;
    }

    public function equals(RegistryKey $other): bool
    {
        return $this->segments === $other->segments();
    }

    public function __toString(): string
    {
        return $this->uri;
    }
}
