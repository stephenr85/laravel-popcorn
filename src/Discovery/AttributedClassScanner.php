<?php

namespace Rushing\Popcorn\Discovery;

use ReflectionClass;
use ReflectionException;
use Symfony\Component\Finder\Finder;

/**
 * A generic, resource/realm-agnostic file→class→attribute scanner.
 *
 * Enumerates the `*.php` files under the given paths, derives each file's FQCN from its
 * `namespace` + `class`/`enum`/`interface`/`trait` declaration, loads it, and keeps only the
 * class-strings that CARRY the requested attribute. It knows nothing about resources, realms,
 * or any consumer's vocabulary — callers pass the attribute class they care about
 * (`#[AdminResource]`, `#[Realm]`, …) and get back the matching `class-string[]`.
 *
 * Extracted from `Splicewire\Beam\Frame\AdminResourceRegistry::scanPaths()` /
 * `classNameFromFile()` (they were duplicated verbatim in beam's `RealmDiscovery`); this is the
 * single home for the machinery so every discoverer consumes one implementation.
 *
 * Behaviour preserved from the beam original:
 *  - non-existent paths are silently skipped (filtered, never an error);
 *  - files whose derived class does not exist / is not loadable are skipped;
 *  - only attribute-carrying classes are returned — an un-attributed class under a scanned path is
 *    ignored, so a path may point at a whole Data directory.
 */
class AttributedClassScanner
{
    /**
     * Scan the given filesystem paths and return the class-strings carrying $attributeClass.
     *
     * @param  array<int, string>  $paths  filesystem paths (files or directories) to scan
     * @param  class-string  $attributeClass  the attribute a class must carry to be kept
     * @param  bool  $instanceof  when true (default), preset SUBCLASSES of the attribute
     *                            match too (`ReflectionAttribute::IS_INSTANCEOF`); when
     *                            false, only the exact attribute matches
     * @return list<class-string>
     */
    public function scan(array $paths, string $attributeClass, bool $instanceof = true): array
    {
        $existing = array_filter($paths, 'file_exists');

        if ($existing === []) {
            return [];
        }

        $found = [];

        $finder = (new Finder)->files()->name('*.php')->in($existing);

        foreach ($finder as $file) {
            $class = $this->classNameFromFile($file->getRealPath());

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            if ($this->hasAttribute($class, $attributeClass, $instanceof)) {
                $found[] = $class;
            }
        }

        return $found;
    }

    /**
     * Whether $class carries $attributeClass (optionally matching preset subclasses).
     *
     * @param  class-string  $class
     * @param  class-string  $attributeClass
     */
    public function hasAttribute(string $class, string $attributeClass, bool $instanceof = true): bool
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException) {
            return false;
        }

        $flags = $instanceof ? \ReflectionAttribute::IS_INSTANCEOF : 0;

        return $reflection->getAttributes($attributeClass, $flags) !== [];
    }

    /**
     * Derive a class-string from a PHP file's `namespace` + type declaration. Matches
     * class/enum/interface/trait so a scanned directory of mixed declarations resolves.
     *
     * @return class-string|null
     */
    public function classNameFromFile(string $path): ?string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $namespace = preg_match('/namespace\s+([^;]+);/', $contents, $ns) ? trim($ns[1]) : '';

        if (! preg_match('/\b(?:class|enum|interface|trait)\s+(\w+)/', $contents, $type)) {
            return null;
        }

        /** @var class-string */
        return $namespace === '' ? $type[1] : $namespace.'\\'.$type[1];
    }
}
