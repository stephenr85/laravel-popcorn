<?php

namespace Rushing\Popcorn\Laravel\Console;

use Illuminate\Console\Command;
use Rushing\Popcorn\Laravel\PopcornManager;
use Rushing\Popcorn\Registries\CarriesDeclaration;
use Rushing\Popcorn\Registries\Exceptions\InvalidRegistryKey;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\RecordsRegistrants;
use Rushing\Popcorn\Registries\RecordsSupersession;
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
 * complete. Routing already reads the index structurally, so the call that matters here is the routed
 * registry's own `unfiltered()`.
 *
 * ⚠️ **The double call this docblock used to prescribe is gone (ticket 45).**
 * `RegistryIndex::unfiltered()` escaped one level — it unfiltered which registries you could see and
 * handed back the same live singletons still carrying the pushed authorizer — so callers had to
 * `unfiltered()` again on each registry. It is deep now. This command never depended on that (it routes
 * first, then unfilters the store), which is why it was already correct; a caller that went through
 * `$index->unfiltered()->resolve(...)` was not.
 *
 * ## The suggester is filtered, and that asymmetry is deliberate
 *
 * The listing is unfiltered and the "did you mean" beside it is not, because
 * {@see PopcornManager::suggest()} is the shared helper this command exists to be the second consumer
 * of (13 D6) and 13 D3 fixes it filtered. Under the estate's trusted-shell policy the two can disagree
 * only when a host has installed an authorizer that denies the console actor — where the honest
 * behaviour is the one that leaks less.
 *
 * ## The registrant column, finally
 *
 * 13 D10 chartered this as *"lists one registry's live keys **with their registrants**"* and ticket 32
 * could not ship the second half: `by` was written on every entry by `register()` and read back by none
 * of `Registry`'s seven methods (ticket 29 D2), reachable only for a DISPLACED entry through
 * `RecordsSupersession`. **Ticket 48 landed {@see RecordsRegistrants}** and the column is here.
 *
 * Two properties of the rendering, both deliberate:
 *
 * - **`null` is the majority case and prints as `—`, not as a blank.** 29 D2 measured 13 of 38 entries
 *   carrying `$by` at all, and of the registrants that exist, 8 of 10 name the registering registry's
 *   own class. A blank would read as "the column is broken"; a dash reads as "nobody said."
 * - **The bare one-key-per-line output survives when no entry names a registrant**, so a pipe into
 *   `grep`/`xargs` keeps working on the majority of roots. The table appears only when there is
 *   something to put in it.
 *
 * The JSON gains `registrants` as a `key => by` map beside the existing `keys` list rather than
 * reshaping `keys` into objects — an additive key cannot break a reader that already parses this.
 */
class KeysCommand extends Command
{
    protected $signature = 'popcorn:keys
        {prefix : A registry root, or any key prefix under one}
        {--supersessions : List what was OVERWRITTEN under the prefix instead of what is live}
        {--json : Emit the keys as JSON}';

    protected $description = "One registry's live keys at or under a prefix — the entry-level read popcorn:registries does not do.";

    public function handle(PopcornManager $popcorn): int
    {
        $argument = $this->argument('prefix');

        try {
            // `argument()` is typed `array|bool|string|null` for the whole console surface; THIS
            // signature declares `{prefix}` as a required single value, so the only reachable case is
            // string. Asked rather than cast, because a cast of the array case is a fatal.
            $prefix = Key::parse(is_string($argument) ? $argument : '');
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

        if ($this->option('supersessions')) {
            return $this->reportSupersessions($registry, $prefix, $keys);
        }

        $registrants = $this->registrantsFor($registry, $keys);

        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['prefix' => (string) $prefix, 'keys' => $keys, 'registrants' => $registrants],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        if ($keys === []) {
            $this->components->warn("No live entries at or under `{$prefix}`.");
            $this->suggest($popcorn, $prefix);

            return self::SUCCESS;
        }

        // The table only appears when there is something to put in it — see the class docblock. A root
        // where nobody named a registrant keeps the pipe-friendly one-key-per-line output it had.
        if (array_filter($registrants) === []) {
            foreach ($keys as $key) {
                $this->line($key);
            }

            return self::SUCCESS;
        }

        $this->table(
            ['Key', 'Registered by'],
            array_map(fn (string $key): array => [$key, $registrants[$key] ?? '—'], $keys),
        );

        return self::SUCCESS;
    }

    /**
     * `--supersessions` — what was OVERWRITTEN under the prefix, oldest first.
     *
     * **This is the reader registry-kernel ticket 48 was opened to find, and it is on the CLI channel
     * rather than the doctor channel — deliberately.**
     *
     * The estate's instinct for "something must read this" is a doctor audit, and 48's question 1
     * reached for one. It cannot be built yet, and the reason is not effort: **a check needs a
     * discriminator, and `$by`'s content is a tautology today.** 29 D2 measured the live population — 38
     * entries, 13 carrying `$by`, and of 10 distinct registrants **8 are the registering registry's own
     * class**. 48's proposed discriminator is *"did a registrar displace a hand registration, or the
     * reverse"*, which needs `$by` to name a package or a provider. Nothing does. A gate over that field
     * today would row every supersession, and 19 D1's whole point is that the DESIGNED case — a
     * consumer's `register()` displacing a registrar's seed — is most of them. Noise in a gate is how a
     * gate stops being read.
     *
     * So: **report now, gate when tickets 37/38 have set a registrant vocabulary that names a package or
     * a provider.** A report is honest about being a report; a green gate over a tautology is not.
     *
     * ## What this cannot see, said here rather than implied by a clean run
     *
     * A registry that does not implement {@see RecordsSupersession} has no history and answers nothing —
     * `Reject` and `Admit` registries legitimately (nothing is ever displaced under either), and
     * {@see \Rushing\Popcorn\Registries\ConfigRegistry} deliberately, because its store is a config
     * array it does not own.
     *
     * **`ConfigRegistry`'s hole is bigger than its own absence, and it is worth knowing.** The estate's
     * config-fed registrants bypass `register()` entirely — `laravel-data-filters` and `laravel-frame`
     * read the config array, append under an `in_array` guard, and write it back — so `OnDuplicate` never
     * runs on that path at all and the record dies with the projection. Today that is safe: the map shape
     * cannot represent a duplicate, and the list shape collides only when two registrants register the
     * same class, which the guard already makes a no-op. **The first registrant that writes through
     * `register()` instead of appending gets an unrecorded overwrite** (19 D5). An empty result here is
     * not evidence of no overwrite on those roots.
     *
     * @param  Registry<mixed>  $registry
     * @param  list<string>  $keys
     */
    protected function reportSupersessions(Registry $registry, Key $prefix, array $keys): int
    {
        $store = $registry->unfiltered();

        if (! $store instanceof RecordsSupersession) {
            $this->components->warn(
                "`{$prefix}` is held by a registry that records no supersession history, so there is "
                    .'nothing to report — not "nothing was overwritten". Reject and Admit registries never '
                    .'displace anything; ConfigRegistry refuses the record because it does not own its store.'
            );

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($keys as $key) {
            foreach ($store->superseded($key) as $displaced) {
                $rows[] = [$key, $displaced->by ?? '—', (string) $displaced->sequence];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                [
                    'prefix' => (string) $prefix,
                    'supersessions' => array_map(
                        fn (array $row): array => ['key' => $row[0], 'by' => $row[1] === '—' ? null : $row[1], 'sequence' => (int) $row[2]],
                        $rows,
                    ),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->info("Nothing under `{$prefix}` has been overwritten.");

            return self::SUCCESS;
        }

        $this->table(['Key', 'Displaced entry registered by', 'Sequence'], $rows);

        return self::SUCCESS;
    }

    /**
     * Who registered each of `$keys`, as a `key => by` map — the read ticket 13 D10 chartered and ticket
     * 32 could not ship.
     *
     * Returns an empty map for a registry that does not implement {@see RecordsRegistrants}. That is not
     * a degradation to apologise for: `ConfigRegistry` deliberately does not implement it, because its
     * store is a config array it does not own and keeping a shadow ledger beside it is the
     * reaching-past-the-boundary ticket 08 D11 refuses. The type says so, and this reads the type rather
     * than guessing.
     *
     * Reads through `unfiltered()` for the same reason {@see keysUnder()} does — the keys were listed
     * unfiltered, so a filtered registrant read would print `—` for an entry that has one.
     *
     * @param  Registry<mixed>  $registry
     * @param  list<string>  $keys
     * @return array<string, string|null>
     */
    protected function registrantsFor(Registry $registry, array $keys): array
    {
        $store = $registry->unfiltered();

        if (! $store instanceof RecordsRegistrants) {
            return [];
        }

        $registrants = [];

        foreach ($store->keys() as $key) {
            if (in_array((string) $key, $keys, true)) {
                $registrants[(string) $key] = $store->registrantOf($key);
            }
        }

        return $registrants;
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
     * @param  Registry<mixed>  $registry
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
    /** @param  Registry<mixed>  $registry */
    protected function ownsRootExactly(Registry $registry, Key $prefix): bool
    {
        // {@see CarriesDeclaration} rather than `instanceof BasicRegistry`, which is what this read
        // used to test — the same defect registry-kernel 59 B1 fixed in `RegistryIndex::declarationOf()`,
        // one tier up. An archetype-f registry holds no `BasicRegistry` and declares inline, so the type
        // test would have sent it to a class attribute it deliberately does not carry.
        $declaration = $registry instanceof CarriesDeclaration
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
