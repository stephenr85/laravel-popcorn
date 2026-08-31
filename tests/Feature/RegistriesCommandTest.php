<?php

use Rushing\Popcorn\Registries\RegistryArity;

/**
 * `popcorn:registries` — the index of indexes, and the one production reader of `IsRegistry::$arity`.
 *
 * Registry-kernel ticket 47 made that field a LIST (the read path, outermost first). This suite exists
 * because the field's whole value is that a reader can tell a lookup table from a pipeline, and the
 * command is where that becomes legible: a wire projection that sometimes emits a string and sometimes
 * an array would put the drift back where the list removed it (ticket 16 records `--json` as the
 * presumptive TS shape).
 */
it('emits arity as a list on the wire, even for the one-step case that is 77 of 79 registries', function () {
    registryAt('beam.realm', ['tenant' => 'a']);

    $this->artisan('popcorn:registries', ['--json' => true])->assertSuccessful();

    $rows = json_decode(commandJson('popcorn:registries'), true);
    $row = collect($rows)->firstWhere('root', 'beam.realm');

    expect($row['arity'])->toBe(['pick-one']);
});

it('emits every step of a multi-step arity, in declaration order', function () {
    registryAt('pipelines', ['deploy:web' => 'a'], arity: [RegistryArity::PickOne, RegistryArity::ComposeMany]);

    $rows = json_decode(commandJson('popcorn:registries'), true);
    $row = collect($rows)->firstWhere('root', 'pipelines');

    expect($row['arity'])->toBe(['pick-one', 'compose-many']);
});

it('renders a multi-step arity as one cell and legends BOTH steps', function () {
    registryAt('pipelines', ['deploy:web' => 'a'], arity: [RegistryArity::PickOne, RegistryArity::ComposeMany]);

    $output = commandOutput('popcorn:registries');

    // The table joins the steps; the legend must not stop at the first one, because the second is the
    // half that says what a read of this registry actually DOES with the entry it picked.
    expect($output)->toContain('pick-one › compose-many')
        ->and($output)->toContain(RegistryArity::PickOne->blurb())
        ->and($output)->toContain(RegistryArity::ComposeMany->blurb());
});

it('legends a step once, however many registries share it', function () {
    registryAt('beam.realm', ['tenant' => 'a']);
    registryAt('beam.surface', ['api' => 'b']);

    $output = commandOutput('popcorn:registries');

    expect(substr_count($output, RegistryArity::PickOne->blurb()))->toBe(1);
});

/**
 * `--shadowing` — the reader for what registry-kernel 73 §1 turned from a `describe()` throw into a
 * record (php-popcorn ADR-0001). A record nothing reads is ticket 48's own complaint, filed against
 * this same index, so the reader lands with the record rather than after it.
 */
it('lists the entries that went dark when two described roots overlap', function () {
    registryAt('beam.particle', ['fragments.ops.download' => 'reachable, for now']);
    registryAt('beam.particle.fragments.ops');

    $output = commandOutput('popcorn:registries', ['--shadowing' => true]);

    expect($output)->toContain('beam.particle.fragments.ops.download')
        ->and($output)->toContain('beam.particle.fragments.ops')
        ->and($output)->toContain('answer to two registries');
});

it('says an empty shadowing list is not the same as a clean estate', function () {
    registryAt('beam.realm', ['tenant' => 'a']);

    $output = commandOutput('popcorn:registries', ['--shadowing' => true]);

    // A bare "none" would read as coverage the record does not have: it can only see entries that
    // already existed when one of the two registries was described, and registrars usually fill a
    // registry afterwards. The pointer at the post-boot gate is what distinguishes "nothing there"
    // from "could not look".
    expect($output)->toContain('No shadowed entries were recorded at describe time.')
        ->and($output)->toContain('not the same as none existing')
        ->and($output)->toContain('shadowed-entry');
});
