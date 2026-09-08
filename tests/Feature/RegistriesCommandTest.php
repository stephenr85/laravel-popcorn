<?php


it('emits the entry type and optional description without the retired metadata', function () {
    registryAt('beam.realm', ['tenant' => 'a'], description: 'Realm definitions available to this host.');

    $this->artisan('popcorn:registries', ['--json' => true])->assertSuccessful();

    $rows = json_decode(commandJson('popcorn:registries'), true, flags: JSON_THROW_ON_ERROR);
    $row = collect($rows)->firstWhere('root', 'beam.realm');

    expect($row)->not->toBeNull()
        ->and($row['entryType'])->toBe('mixed')
        ->and($row['description'])->toBe('Realm definitions available to this host.')
        ->and(array_keys($row))->toBe([
            'root', 'entryType', 'onKeyDuplicate', 'populationRequirement', 'description', 'sources', 'owner', 'order',
        ]);
});

it('allows a registry to omit its description', function () {
    registryAt('beam.realm', ['tenant' => 'a']);

    $rows = json_decode(commandJson('popcorn:registries'), true, flags: JSON_THROW_ON_ERROR);
    $row = collect($rows)->firstWhere('root', 'beam.realm');

    expect($row)->not->toBeNull()
        ->and($row['description'])->toBeNull();
});

it('renders registry descriptions as ordinary usage information', function () {
    registryAt('pipelines', ['deploy:web' => 'a'], description: 'Select a pipeline by key, then run its stages in order.');

    $output = commandOutput('popcorn:registries');

    expect($output)->toContain('Entry type')
        ->and($output)->toContain('Descriptions:')
        ->and($output)->toContain('Select a pipeline by key, then run its stages in order.')
        ->and($output)->not->toContain('Arity', 'Caveats:', 'Resolving, by arity');
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
