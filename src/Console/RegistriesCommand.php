<?php

namespace Rushing\Popcorn\Laravel\Console;

use Illuminate\Console\Command;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Filled;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Registrar;
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
 *   or not injection points, one had zero members, and the declared seam lied in three registries. No filter
 *   replaces it yet — one on registrar class is trivially addable, and waits for a populated index to be
 *   worth filtering.
 * - **The `Where` column, replaced by `Fill from`.** Ticket 24 landed `Registrar::source()`, so the column
 *   is now DERIVED: a registry reports the registrars it actually holds and each one says where it reads
 *   from. A hand-written `where` could disagree with the code beside it; this cannot (07 D4).
 *   A registry with no registrars renders `hand`, which is a fact about it rather than a gap — most of the
 *   estate's registries are filled by consumers calling `register()`, and saying so is the answer to "how
 *   do I contribute?"
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
    protected $signature = 'popcorn:registries
        {--json : Emit the index as JSON}
        {--shadowing : List entries that went dark because two described roots overlap, instead of the index}';

    protected $description = 'The index of indexes: every registry in the estate, the root it owns, and how a read engages it.';

    public function handle(RegistryIndex $index): int
    {
        // ⚠️ The catalogue is one of the three readers registry-kernel 73 D3.2 promised for the unbaked
        // state, and it is the one an operator reaches for first. Without this it dies on a stack trace
        // naming `unfiltered()` — technically loud, and useless: the reader whose whole job is "what is
        // in the index" must be able to say "the list was never baked" in words.
        if ($index->isUnbaked()) {
            $this->components->error('The registry index has no baked membership list, so this command '
                .'cannot answer. Run `'.\Rushing\Popcorn\Laravel\Baking\BakedRegistryManifest::COMMAND.'`.');
            $this->line('  <comment>This is NOT the same as an estate with no registries.</comment> A host '
                .'that genuinely declares none has an artifact listing nothing and reports an empty table; '
                .'this host has no artifact at all, so nothing is described and nothing would be authorized.');

            return self::FAILURE;
        }

        if ($this->option('shadowing')) {
            return $this->shadowing($index);
        }

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
            ['Root', 'Arity (out)', 'Of', 'Entry type', 'Fill from', 'Owner'],
            array_map(static fn (array $r): array => [
                $r['root'] === '' ? '(root)' : $r['root'],
                implode(' › ', $r['arity']),
                $r['of'],
                $r['entryType'],
                $r['sources'] === [] ? 'hand' : implode("\n", $r['sources']),
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
     * `--shadowing` — the entries that went dark because two described roots overlap on them.
     *
     * The reader for what registry-kernel ticket 73 §1 turned from a `describe()` throw into a
     * {@see \Rushing\Popcorn\Registries\Shadowed} record (php-popcorn ADR-0001). It lands on the CLI
     * channel and not the doctor channel for the reason ticket 48 landed `popcorn:keys --supersessions`
     * there: **this package installs no audits**, and the doctor-channel reader for the same condition
     * already exists and already gates — `Splicewire\Beam\Doctor\RegistryConformanceAudit`'s
     * `shadowed-entry` check, which reads the LIVE index post-boot and therefore sees a strict superset
     * of these records. Writing a second gate here would be two checks answering one question in two
     * packages, which is the failure 13 D10 named about this very command.
     *
     * What this adds that the audit cannot is **provenance**: who described the registry whose arrival
     * created the overlap, and in what order. That is reconstructible from the record and from nothing
     * else, because the index's post-boot state does not remember which of two roots arrived second.
     *
     * ⚠️ **An empty list is NOT a clean estate**, and the command says so out loud rather than printing a
     * bare "none". The record can only see entries that already existed at the moment one of the two
     * registries was described, and a registry is normally described before its registrars fill it. A
     * reader that cannot distinguish "nothing there" from "could not look" is this estate's signature
     * defect; the pointer at the audit is what distinguishes them here.
     */
    protected function shadowing(RegistryIndex $index): int
    {
        $records = $index->shadowed();

        if ($records === []) {
            $this->components->info('No shadowed entries were recorded at describe time.');
            $this->line(
                '  <comment>That is not the same as none existing.</comment> A registry is usually described '
                    .'before its registrars fill it, so the entry that collides most often does not exist yet '
                    .'when the index looks. The post-boot reader that sees the rest is '
                    .'`splicewire:beam:doctor`\'s registry-conformance `shadowed-entry` check.'
            );

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Entry that went dark', 'Held by (root)', 'Owned by (root)', 'Described by'],
            array_map(static fn ($s): array => [
                $s->sequence,
                (string) $s->key,
                (string) $s->shallower,
                (string) $s->deeper,
                $s->by ?? '—',
            ], $records),
        );

        $this->newLine();
        $this->components->warn(sprintf(
            '%d entry/entries answer to two registries. A read through the index routes each to the DEEPER '
                .'root and never sees it; a read straight off the shallower registry still answers with it. '
                .'Move the entry, or move the root.',
            count($records),
        ));

        return self::SUCCESS;
    }

    /**
     * One row per described registry, foundation-first.
     *
     * The declaration is read off the OWNER where there is one — under the sanctioned composition pattern
     * the store is a `BasicRegistry` and the attribute lives on the class that holds it (ticket 20 D6).
     *
     * `arity` is a LIST of step values, outermost first, and stays a list even for the one-step case that
     * is 77 of 79 registries — ticket 16 records this projection as the presumptive TS wire shape, and a
     * field that is sometimes a string and sometimes an array is the worst possible thing to put on a
     * wire (ticket 47). The table joins it; `--json` does not.
     *
     * @return list<array{root: string, of: string, arity: non-empty-list<string>, entryType: string, onDuplicate: string, optionality: string, note: string|null, sources: list<string>, owner: string, order: int}>
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
                'arity' => array_map(static fn (RegistryArity $a): string => $a->value, $declaration->arity),
                'entryType' => $declaration->entryType,
                'onDuplicate' => $declaration->onDuplicate->value,
                'optionality' => $declaration->optionality->value,
                'note' => $declaration->note,
                'sources' => $this->sourcesOf($owner, $store),
                'owner' => $owner === null ? ($store === null ? '?' : $store::class) : $owner::class,
                'order' => $declaration->order,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $rows;
    }

    /**
     * Where a registry's entries come from, derived by asking it for its registrars.
     *
     * Asked of the OWNER first and the store second, in that order, because an owner that composes a
     * `BasicRegistry` may expose `attach()`/`registrars()` itself — and where it does, its list is the
     * one a contributor would reach. Falls through to the store, which is where a composed registry's
     * registrars actually live when the owner does not forward.
     *
     * A registry implementing neither reports nothing, and the caller renders that as `hand`.
     *
     * @return list<string>
     */
    protected function sourcesOf(?object $owner, mixed $store): array
    {
        foreach ([$owner, $store] as $candidate) {
            if ($candidate instanceof Filled) {
                return array_map(static fn (Registrar $r): string => $r->source(), $candidate->registrars());
            }
        }

        return [];
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
     * De-duplicated per STEP, not per row: a two-step registry rendered `pick-one › compose-many` needs
     * both lines, and the row it shares a step with must not suppress either. This is where a multi-level
     * arity is turned into prose, and it is the renderer's job rather than the enum's —
     * {@see RegistryArity::blurb()}'s bound is that a case carries prose about ITSELF and nothing else,
     * so the join lives here (ticket 47). A second renderer of the same thing is when a shared helper
     * earns its place; one does not.
     *
     * @param  list<array{arity: non-empty-list<string>, ...}>  $rows
     */
    protected function legend(array $rows): void
    {
        $this->components->info('Resolving, by arity (how many entries a read engages, outermost first):');

        $seen = [];

        foreach ($rows as $row) {
            foreach ($row['arity'] as $step) {
                if (in_array($step, $seen, true)) {
                    continue;
                }

                $seen[] = $step;
                $arity = RegistryArity::from($step);
                $this->line("  <comment>{$arity->value}</comment> — {$arity->blurb()}");
            }
        }
    }
}
