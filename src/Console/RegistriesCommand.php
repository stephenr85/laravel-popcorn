<?php

namespace Rushing\Popcorn\Laravel\Console;

use Illuminate\Console\Command;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;

/**
 * `php artisan popcorn:registries` — the index of indexes. Lists every registry that has described itself
 * into the {@see RegistryIndex}: the root of the keyspace it owns, what it is a registry OF, and how many
 * entries a read engages. The map of "where are the seams" that otherwise lives only in people's heads — so
 * a new engineer (or agent) answers "what registers X, and where do I inject?" from the CLI instead of a
 * codebase sweep.
 *
 * ## This is a RELOCATION, not a rewrite
 *
 * It is `splicewire/laravel-beam`'s `splicewire:beam:manifests` moved here with the index it renders
 * (registry-kernel tickets 04, 13 D10, 21). Full cutover: the beam command is gone and there is no alias.
 * Writing a second command beside a surviving original — two commands answering one question in two
 * packages after the index had already moved — is the failure 13 D10 named.
 *
 * ## What the relocation dropped, and why
 *
 * - **The `--seam=` filter and the injection legend.** `ManifestSeam` is deleted (ticket 07 D1): its seven
 *   shapes were never a taxonomy of injection points, they were a census of how ~55 registries happened to
 *   get filled *before they shared a contract*. Three of the seven described things that are not registries
 *   or not injection points, one had zero members, and the declared seam lied in three registries. Filtering
 *   is replaced by filtering on **registrar class**, once ticket 24 lands `Registrar`.
 * - **The `Where` column.** Superseded by `Registrar::source()` — derived from the registrars a registry
 *   actually has rather than hand-written beside them, so it cannot drift (07 D4).
 * - **The `registerHint` one-liner.** With `of` + `entryType` + the resolve/tryResolve pair, most of the
 *   estate's 52 hand-written hints were derivable; the residue is caveats, which is what `note` carries.
 *   That deleted 52 drift surfaces (01 D10).
 *
 * ## It reads `unfiltered()`
 *
 * Artisan-only tooling, which is ticket 09 D11's sanctioned case for the explicit escape: a catalogue that
 * silently omitted the registries the running actor cannot see would be a catalogue you cannot trust to be
 * complete, and the estate's stated policy is that the shell is trusted. `popcorn:keys` takes the same path
 * for the same reason.
 */
class RegistriesCommand extends Command
{
    protected $signature = 'popcorn:registries {--json : Emit the index as JSON}';

    protected $description = 'The index of indexes: every registry in the estate, the root it owns, and how a read engages it.';

    public function handle(RegistryIndex $index): int
    {
        $rows = $this->rows($index);

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->warn('No registries have described themselves into the index.');

            return self::SUCCESS;
        }

        $this->table(
            ['Root', 'Arity (out)', 'Of', 'Entry type', 'Owner'],
            array_map(static fn (array $r): array => [
                $r['root'] === '' ? '(root)' : $r['root'],
                $r['arity'],
                $r['of'],
                $r['entryType'],
                $r['owner'],
            ], $rows),
        );

        $this->newLine();
        $this->legend($rows);

        $notes = array_values(array_filter($rows, static fn (array $r): bool => $r['note'] !== null));

        if ($notes !== []) {
            $this->newLine();
            $this->components->info('Caveats:');

            foreach ($notes as $row) {
                $this->line("  <comment>{$row['root']}</comment> — {$row['note']}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * One row per described registry, foundation-first.
     *
     * The declaration is read off the OWNER where there is one — under the sanctioned composition pattern
     * the store is a `BasicRegistry` and the attribute lives on the class that holds it (ticket 20 D6).
     *
     * @return list<array{root: string, of: string, arity: string, entryType: string, onDuplicate: string, optionality: string, note: string|null, owner: string, order: int}>
     */
    protected function rows(RegistryIndex $index): array
    {
        $unfiltered = $index->unfiltered();
        $rows = [];

        foreach ($unfiltered->keys() as $key) {
            $owner = $index->owner($key);
            $store = $unfiltered->tryResolve($key);
            $declaration = $this->declarationOf($owner, $store);

            if ($declaration === null) {
                continue;
            }

            $rows[] = [
                'root' => (string) $key,
                'of' => $declaration->of,
                'arity' => $declaration->arity->value,
                'entryType' => $declaration->entryType,
                'onDuplicate' => $declaration->onDuplicate->value,
                'optionality' => $declaration->optionality->value,
                'note' => $declaration->note,
                'owner' => $owner === null ? ($store === null ? '?' : $store::class) : $owner::class,
                'order' => $declaration->order,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $rows;
    }

    protected function declarationOf(?object $owner, mixed $store): ?IsRegistry
    {
        foreach ([$owner, $store] as $candidate) {
            if (is_object($candidate) && ($declaration = IsRegistry::of($candidate)) !== null) {
                return $declaration;
            }
        }

        // A store described with no owner is the uncomposed case: a `BasicRegistry` built by
        // `BasicRegistry::for($owner)`, which already read the declaration off the owning class. Asking it
        // for what it is holding is the same route `RegistryIndex::declarationOf()` takes.
        return $store instanceof BasicRegistry ? $store->declaration() : null;
    }

    /**
     * The arity legend, de-duplicated: one line per arity actually present.
     *
     * The arity column is the point of this table — it is what makes "is this a registry or a manifest?"
     * legible (canon: `the-seam-is-a-registry`): both are one primitive, and arity is what actually differs.
     *
     * @param  list<array{arity: string, ...}>  $rows
     */
    protected function legend(array $rows): void
    {
        $this->components->info('Resolving, by arity (how many entries a read engages):');

        $seen = [];

        foreach ($rows as $row) {
            if (in_array($row['arity'], $seen, true)) {
                continue;
            }

            $seen[] = $row['arity'];
            $arity = RegistryArity::from($row['arity']);
            $this->line("  <comment>{$arity->value}</comment> — {$arity->blurb()}");
        }
    }
}
