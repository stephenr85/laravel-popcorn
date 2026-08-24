<?php

namespace Rushing\Popcorn\Laravel\Console;

use Illuminate\Console\Command;
use Rushing\Popcorn\Laravel\PopcornManager;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Exceptions\InvalidRegistryKey;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * `php artisan popcorn:keys beam.realm` — one registry's live keys, one per line.
 *
 * The entry-level read {@see RegistriesCommand} deliberately does not do: that command lists REGISTRIES
 * (root, arity, what they are of) and carries no entries and no entry count, which is the split ticket
 * 13 D10 drew when it found there had never been a duplication to resolve. This is the other half —
 * "what is actually IN it", the question a miss message provokes.
 *
 * ## It reads `unfiltered()`, and it must do so twice
 *
 * Artisan-only tooling is ticket 09 D11's sanctioned case for the explicit escape, and a catalogue that
 * silently omitted what the running actor cannot see would be a catalogue you cannot trust to be
 * complete. Note the DOUBLE call: `RegistryIndex::unfiltered()` unfilters which registries you can see,
 * and hands back the same live singletons still carrying the pushed authorizer, so a second
 * `unfiltered()` on the registry itself is what unfilters its ENTRIES (ticket 17 D6). Routing already
 * reads the index structurally, so the one that matters here is the registry's own.
 *
 * ## The suggester is filtered, and that asymmetry is deliberate
 *
 * The listing is unfiltered and the "did you mean" beside it is not, because
 * {@see PopcornManager::suggest()} is the shared helper this command exists to be the second consumer
 * of (13 D6) and 13 D3 fixes it filtered. Under the estate's trusted-shell policy the two can disagree
 * only when a host has installed an authorizer that denies the console actor — where the honest
 * behaviour is the one that leaks less.
 *
 * ## No registrant column, yet
 *
 * 13 D10 chartered this as "keys with their registrants" and the contract cannot answer the second
 * half: `by` is written on every entry by `register()` and none of `Registry`'s seven methods reads it
 * back (ticket 29 D2). It is readable only for a DISPLACED entry, through
 * {@see \Rushing\Popcorn\Registries\RecordsSupersession}. Adding a provenance read is ticket 48's, and
 * this command is its second waiting consumer.
 */
class KeysCommand extends Command
{
    protected $signature = 'popcorn:keys {prefix : A registry root, or any key prefix under one} {--json : Emit the keys as JSON}';

    protected $description = "One registry's live keys at or under a prefix — the entry-level read popcorn:registries does not do.";

    public function handle(PopcornManager $popcorn): int
    {
        try {
            $prefix = Key::parse($this->argument('prefix'));
        } catch (InvalidRegistryKey $illegal) {
            $this->components->error($illegal->getMessage());

            return self::FAILURE;
        }

        $registry = $popcorn->routeTo($prefix);

        if ($registry === null) {
            $this->components->error("No registry claims `{$prefix}`.");
            $this->suggest($popcorn, $prefix);

            return self::FAILURE;
        }

        $keys = $this->keysUnder($registry, $prefix);

        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['prefix' => (string) $prefix, 'keys' => $keys],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        if ($keys === []) {
            $this->components->warn("No live entries at or under `{$prefix}`.");
            $this->suggest($popcorn, $prefix);

            return self::SUCCESS;
        }

        foreach ($keys as $key) {
            $this->line($key);
        }

        return self::SUCCESS;
    }

    /**
     * Every live key the routed registry holds at or under `$prefix`, in registration order.
     *
     * Segment-wise via {@see Key::isUnder()}, the same comparison the read path makes, so `beam.realms`
     * does not appear under `beam.realm`.
     *
     * A foreign {@see RegistryKey} is never stamped with a root and so is under nothing — it is listed
     * when the prefix names the registry's own root, and only then, because a foreign-keyed registry is
     * one flat keyspace of its owner's devising with no sub-prefix to speak of (ticket 20 D3).
     *
     * @return list<string>
     */
    protected function keysUnder(Registry $registry, Key $prefix): array
    {
        $atRoot = $this->ownsRootExactly($registry, $prefix);
        $keys = [];

        foreach ($registry->unfiltered()->keys() as $key) {
            $keep = $key instanceof Key
                ? $key->equals($prefix) || $key->isUnder($prefix)
                : $atRoot;

            if ($keep) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * Whether `$prefix` is the routed registry's own declared root — the only prefix a foreign-keyed
     * registry can be addressed by.
     *
     * Asked of the store rather than derived from the index, because the index answers "which root
     * routes here" and this needs "which root did this registry declare", which are the same fact by
     * two routes and only one of them is a walk.
     */
    protected function ownsRootExactly(Registry $registry, Key $prefix): bool
    {
        $declaration = $registry instanceof BasicRegistry
            ? $registry->declaration()
            : IsRegistry::of($registry);

        return $declaration !== null && $declaration->rootKey()->equals($prefix);
    }

    /**
     * Nearest keys to the one that missed, through the shared helper — this command being the second
     * consumer is what earns that helper its place on {@see PopcornManager} rather than inside the rule.
     */
    protected function suggest(PopcornManager $popcorn, Key $prefix): void
    {
        $suggestions = $popcorn->suggest($prefix);

        if ($suggestions === []) {
            return;
        }

        $this->components->info('Did you mean: '.implode(', ', $suggestions));
    }
}
