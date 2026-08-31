<?php

namespace Rushing\Popcorn\Laravel\Console;

use Illuminate\Console\Command;
use Rushing\Popcorn\Laravel\Baking\BakedRegistryManifest;
use Rushing\Popcorn\Laravel\Baking\DeclaredRegistryScan;

/**
 * `php artisan popcorn:registries:cache` — bake the membership list every boot reads.
 *
 * Registry-kernel ticket 73: `#[IsRegistry]` on the class is the single authoring act, and this is what
 * turns it into index membership. Run from `post-autoload-dump` beside `package:discover`, so a host
 * that installs is a host that is baked.
 *
 * **It says what it did NOT bake.** A scan that quietly returns a shorter list is this estate's
 * signature defect, so the command reports the classes it could not autoload and the declared classes
 * that cannot conform — the two ways the list can be short for reasons that are not "nothing is there".
 */
class CacheRegistriesCommand extends Command
{
    protected $signature = 'popcorn:registries:cache';

    protected $description = 'Bake the registry membership list every boot reads (root => class-string).';

    public function handle(DeclaredRegistryScan $scan, BakedRegistryManifest $manifest): int
    {
        $result = $scan->run();

        $manifest->write($result['map']);

        $this->components->info(sprintf(
            'Baked %d registry root(s) from %d scan root(s) into %s',
            count($result['map']),
            count($result['roots']),
            $manifest->path(),
        ));

        if ($result['nonConforming'] !== []) {
            $this->components->warn(sprintf(
                'NOT baked — %d declared class(es) do not implement the Registry contract, so nothing can '
                    .'describe them: %s. This is the registry-conformance `contract` check, which already '
                    .'gates it.',
                count($result['nonConforming']),
                implode(', ', $result['nonConforming']),
            ));
        }

        // ⚠️ The baked class-string must be the container key the host BINDS, and nothing else checks
        // that. A `class_alias` is one symbol under two names and the container keys on the string; an
        // interface binding (`singleton(Schema::class, NodeSchema::class)`) puts the singleton under a
        // name the declaration does not carry. In both shapes `make($declaredClass)` builds a FRESH
        // object, so the index would hold a real-looking registry that nobody writes into — a wrong
        // ANSWER, not an error. Both were live when this check was written and both are now repaired:
        // `CorpusStreamRegistry` (alias, at `~/Herd/splicewire-app`) and `NodeSchema` (concrete under an
        // interface, in blockdoc), 2 of 84.
        //
        // ⚠️ Only a DIVERGENCE is reported, never a mere absence. A declared registry that nothing binds
        // at this host is auto-resolved fresh and empty, and that is the honest answer rather than a
        // defect: the owning package's wiring is simply not composed here. Measured — reporting bare
        // absence fired at 13 of 14 `~/Herd` roots (`codegen.generators` alone accounts for 13, because
        // `rushing/laravel-codegen` is installed at one host), and a warning that fires almost everywhere
        // for a non-defect is a warning nobody reads.
        $diverging = [];

        foreach ($result['map'] as $root => $entry) {
            if ($this->laravel->bound($entry['class'])) {
                continue;
            }

            $short = class_exists($entry['class']) ? (new \ReflectionClass($entry['class']))->getShortName() : null;

            $container = \Illuminate\Container\Container::getInstance();

            foreach (array_keys($container->getBindings()) as $abstract) {
                $abstract = (string) $abstract;

                if ($short !== null && $abstract !== $entry['class'] && str_ends_with($abstract, '\\'.$short)) {
                    $diverging[$root] = $entry['class'].' (bound as '.$abstract.')';

                    break;
                }
            }
        }

        if ($diverging !== []) {
            $this->components->warn(sprintf(
                'BINDING — %d baked class(es) are bound here under a DIFFERENT name, so resolving the '
                    .'declared one builds a fresh object rather than the host\'s: %s. Bind the declared '
                    .'class, or forward the other name to it.',
                count($diverging),
                implode('; ', array_map(
                    static fn (string $root, string $detail): string => $root.' => '.$detail,
                    array_keys($diverging),
                    $diverging,
                )),
            ));
        }

        if ($result['skipped'] !== []) {
            $this->components->warn(sprintf(
                'REACH — %d class(es) under the scan roots could not be autoloaded, so the bake cannot '
                    .'vouch for them either way: %s.',
                count($result['skipped']),
                implode(', ', array_keys($result['skipped'])),
            ));
        }

        return self::SUCCESS;
    }
}
