<?php

/**
 * `popcorn:registries:docs`: the framework-free package-tier registry docs check
 * (`Rushing\Popcorn\Registries\Docs\RegistryDocsCheck`, registry-docs ticket 04), reached from artisan.
 * The rules are tested in `rushing/php-popcorn`. These tests prove the wiring: exit codes, the
 * inactive ratchet, and that a failure names its root.
 */
beforeEach(function () {
    $this->fixtures = dirname(__DIR__).'/Fixtures/RegistryDocs';

    // The check reaches declarations through autoload, and these fixture sources sit outside PSR-4.
    require_once $this->fixtures.'/covered/src/CoveredFixtureRegistry.php';
    require_once $this->fixtures.'/uncovered/src/UncoveredFixtureRegistry.php';
});

it('passes a package whose every declared root has its conventional page', function () {
    $this->artisan('popcorn:registries:docs', ['path' => $this->fixtures.'/covered'])
        ->expectsOutputToContain('1 declared roots in this package (empty root excluded): 1 page')
        ->assertSuccessful();
});

it('fails a package with docs/registries/ and an uncovered root, naming the root', function () {
    $this->artisan('popcorn:registries:docs', ['path' => $this->fixtures.'/uncovered'])
        ->expectsOutputToContain('`fixture.docs.uncovered` is declared and uncovered')
        ->assertFailed();
});

it('is inactive, and passes, where the package has no docs/registries/ directory', function () {
    $package = sys_get_temp_dir().'/popcorn-docs-inactive-'.bin2hex(random_bytes(4));
    mkdir($package.'/src', 0700, true);
    copy($this->fixtures.'/uncovered/src/UncoveredFixtureRegistry.php', $package.'/src/UncoveredFixtureRegistry.php');

    try {
        $this->artisan('popcorn:registries:docs', ['path' => $package])
            ->expectsOutputToContain('inactive (no docs/registries/ directory')
            ->assertSuccessful();
    } finally {
        unlink($package.'/src/UncoveredFixtureRegistry.php');
        rmdir($package.'/src');
        rmdir($package);
    }
});

it('reports JSON for tooling', function () {
    $this->artisan('popcorn:registries:docs', ['path' => $this->fixtures.'/uncovered', '--json' => true])
        ->expectsOutputToContain('"fixture.docs.uncovered": null')
        ->assertFailed();
});
