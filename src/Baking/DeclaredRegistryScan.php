<?php

namespace Rushing\Popcorn\Laravel\Baking;

use Illuminate\Contracts\Foundation\Application;
use ReflectionClass;
use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Registry;
use Symfony\Component\Finder\Finder;

/**
 * The static source scan the bake reads: every `#[IsRegistry]` class on disk, as `root => class-string`.
 *
 * Registry-kernel ticket 73 D2 — the membership list is derived from the DECLARATION at build time, not
 * from anybody remembering to call `describe()` in a provider. That is what makes the collapse work at a
 * host where the declaring package, the container binding and the describing provider are three
 * different packages, which phase A measured as the normal case rather than the exception.
 *
 * ## ⚠️ The source pre-filter is mandatory, and it is a safety mechanism rather than an optimisation
 *
 * `AttributedClassScanner` reaches a class through `class_exists()`, which AUTOLOADS. Handing it a
 * package's whole `src/` compiles thousands of unrelated classes, and compiling an arbitrary class in a
 * host that does not install its dependencies is not safe:
 *
 *   - a missing **parent** raises a catchable `Error`, which the scanner swallows and records; but
 *   - a missing **trait** is an `E_COMPILE_ERROR` raised while the class is being declared, and is
 *     **not catchable by anything**.
 *
 * Measured 2026-08-31 at `~/Herd/fable`, `standwell` and `thingsontv`: the scan met
 * `rushing/laravel-surgeon`'s `Mcp\SurgeonMcpServer`, whose `use AuthorizesTools;` comes from an
 * uninstalled package, and the process simply died. Filtering on the attribute's short name in the
 * file's SOURCE first takes the flagship's autoload surface from ~5,000 classes to ~85. **Recall was
 * measured against an unfiltered control rather than assumed: 84 in-population either way, zero lost,
 * zero gained.** Matching the short name keeps an aliased import (`use …\IsRegistry as Reg;`) in the
 * population, because the `use` line names it.
 *
 * ## Where the roots come from
 *
 * `popcorn.bake.paths` when a host sets it; otherwise the host's own `app/` and `src/`, plus one root
 * per installed family package read from `vendor/composer/installed.json`. Composer's own manifest is
 * used rather than a `vendor/*` glob for the reason `Rushing\Surgeon\Conformance\InstalledPackages`
 * already does it that way: it names exactly what is installed, needs no globbing, and cannot walk into
 * a family package's own nested `vendor/` tree.
 */
class DeclaredRegistryScan
{
    private AttributedClassScanner $scanner;

    public function __construct(
        private Application $app,
        ?AttributedClassScanner $scanner = null,
    ) {
        $this->scanner = $scanner ?? new AttributedClassScanner;
    }

    /**
     * @return array{map: array<string, array{class: class-string, by: string}>, roots: list<string>, skipped: array<class-string, string>, nonConforming: list<class-string>}
     */
    public function run(): array
    {
        $roots = $this->paths();
        $map = [];
        $nonConforming = [];

        foreach ($this->scanner->scan($this->candidateFiles($roots), IsRegistry::class, instanceof: false) as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            if (! $reflection->implementsInterface(Registry::class)) {
                // Declared and unable to conform, so it could never be described by any route. Owned and
                // GATED by beam's registry-conformance `contract` check; counted here so the bake can
                // say what it did not bake instead of quietly shipping a shorter list.
                $nonConforming[] = $class;

                continue;
            }

            $root = $reflection->getAttributes(IsRegistry::class)[0]->newInstance()->root;

            // ⚠️ The index itself is never baked. It declares the ZERO-SEGMENT root — it owns the whole
            // keyspace — and it describes itself at construction, so baking it would ask a
            // half-constructed index to resolve itself, and `Key::of('')` is not even a legal key.
            // Measured the hard way at `~/Herd/tower`: the first bake included it and the next boot died
            // on `InvalidRegistryKey: `` is not a legal registry key`.
            //
            // Skipped by ROOT rather than by class name, so a consumer that subclasses the index is
            // covered by the same rule for the same reason.
            if ($root === '') {
                continue;
            }

            $map[$root] = ['class' => $class, 'by' => $class];
        }

        return [
            'map' => $map,
            'roots' => $roots,
            'skipped' => $this->scanner->unloadable(),
            'nonConforming' => $nonConforming,
        ];
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        $configured = config('popcorn.bake.paths');

        if (is_array($configured) && $configured !== []) {
            return array_values(array_filter(array_map('strval', $configured), 'is_dir'));
        }

        $found = [];

        foreach (['app', 'src'] as $dir) {
            if (is_dir($path = $this->app->basePath($dir))) {
                $found[(string) realpath($path)] = true;
            }
        }

        foreach ($this->installedPackageRoots() as $path) {
            $found[$path] = true;
        }

        return array_keys($found);
    }

    /**
     * One source root per installed package, from composer's own manifest.
     *
     * `<pkg>/src` is preferred over `<pkg>`, and a package root is never descended into whole, for the
     * reason beam's `HostScanRoots` gives: every family package carries its own dev `vendor/` tree, so
     * descending re-scans the estate once per package.
     *
     * @return list<string>
     */
    protected function installedPackageRoots(): array
    {
        $manifest = $this->app->basePath('vendor/composer/installed.json');

        if (! is_file($manifest)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($manifest), true);
        $packages = $decoded['packages'] ?? $decoded ?? [];
        $vendors = (array) config('popcorn.bake.vendors', ['rushing', 'schemastud', 'splicewire']);
        $found = [];

        foreach (is_array($packages) ? $packages : [] as $package) {
            $name = $package['name'] ?? null;

            if (! is_string($name) || ! in_array(explode('/', $name)[0], $vendors, true)) {
                continue;
            }

            $base = $this->app->basePath('vendor/'.$name);
            $src = is_dir($base.'/src') ? $base.'/src' : $base;

            if (is_dir($src) && ($real = realpath($src)) !== false) {
                $found[$real] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * The files worth autoloading: those whose source text names {@see IsRegistry} at all.
     *
     * @param  list<string>  $roots
     * @return list<string>
     */
    protected function candidateFiles(array $roots): array
    {
        $marker = (new ReflectionClass(IsRegistry::class))->getShortName();
        $files = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach ((new Finder)->files()->name('*.php')->in($root) as $file) {
                $path = (string) $file->getRealPath();

                if (str_contains((string) file_get_contents($path), $marker)) {
                    $files[] = $path;
                }
            }
        }

        return array_values(array_unique($files));
    }
}
