<?php

namespace Rushing\Popcorn\Laravel\Console;

use Illuminate\Console\Command;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Filled;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\RegistryIndex;

/**
 * Lists each registry's root, entry type, description, owner and registrar sources.
 *
 * The catalogue reads the unfiltered index so shell tooling sees the full composition.
 */
class RegistriesCommand extends Command
{
    protected $signature = 'popcorn:registries
        {--json : Emit the index as JSON}
        {--shadowing : List entries that went dark because two described roots overlap, instead of the index}';

    protected $description = 'The index of indexes: every registry in the estate, its entry type, and how it is used.';

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
            ['Root', 'Entry type', 'Fill from', 'Owner'],
            array_map(static fn (array $r): array => [
                $r['root'] === '' ? '(root)' : $r['root'],
                $r['entryType'],
                $r['sources'] === [] ? 'hand' : implode("\n", $r['sources']),
                $r['owner'],
            ], $rows),
        );

        $descriptions = array_values(array_filter($rows, static fn (array $r): bool => $r['description'] !== null));

        if ($descriptions !== []) {
            $this->newLine();
            $this->components->info('Descriptions:');

            foreach ($descriptions as $row) {
                $this->line("  <comment>{$row['root']}</comment> — {$row['description']}");
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
     * @return list<array{root: string, entryType: string, onKeyDuplicate: string, populationRequirement: string, description: string|null, sources: list<string>, owner: string, order: int}>
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
                'entryType' => $declaration->entryType,
                'onKeyDuplicate' => $declaration->onKeyDuplicate->value,
                'populationRequirement' => $declaration->populationRequirement->value,
                'description' => $declaration->description,
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


}
