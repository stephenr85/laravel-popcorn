<?php

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Rushing\Popcorn\Laravel\Baking\BakedRegistryManifest;
use Rushing\Popcorn\Laravel\Baking\TestEnvironmentBake;
use Rushing\Popcorn\Laravel\Console\MakeRegistryCommand;
use Rushing\Popcorn\Registries\ForwardsToRegistryStore;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;

/**
 * `popcorn:make:registry` (make-surface 05): the class it writes is declared, gated and forwarding, it
 * loads, the bake describes it, and the index's authorizer filters it. The beam-docs scaffold line is
 * printed for exactly its root and never called, because a rushing-tier package cannot depend on beam.
 *
 * Each test points the app path at a scratch directory, so a run never writes into testbench's skeleton
 * under vendor/. The app namespace is read first and memoised, because Laravel derives it by matching the
 * app path against composer.json, and the scratch directory is not in it. Tests that load the generated
 * class use a class name no other test uses, because PHP cannot declare a class twice in one process.
 */
beforeEach(function () {
    app()->getNamespace();

    $this->appPath = sys_get_temp_dir().'/popcorn-make-registry-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->appPath);
    app()->useAppPath($this->appPath);
});

afterEach(function () {
    TestEnvironmentBake::forget();
    app()->forgetInstance(RegistryIndex::class);
    File::deleteDirectory($this->appPath);
});

/** @return array{0: int, 1: string} exit code and full output */
function makeRegistry(array $arguments): array
{
    $status = Artisan::call('popcorn:make:registry', $arguments);

    return [$status, Artisan::output()];
}

it('writes a Gated registry that declares its root and its duplicate policy, and reads through the trait', function () {
    [$status] = makeRegistry(['name' => 'ReportFormatRegistry', '--key' => 'app.report-formats']);

    $source = File::get($this->appPath.'/Registries/ReportFormatRegistry.php');

    expect($status)->toBe(0)
        ->and($source)->toContain('namespace App\Registries;')
        ->and($source)->toContain("root: 'app.report-formats',")
        ->and($source)->toContain('onKeyDuplicate: OnKeyDuplicate::Supersede,')
        ->and($source)->toContain('class ReportFormatRegistry implements Gated, Registry')
        ->and($source)->toContain('use ForwardsToRegistryStore;')
        ->and($source)->not->toContain('{{');
});

it('emits a class that loads, conforms, and keeps keys relative in and absolute out', function () {
    makeRegistry(['name' => 'WidgetKindRegistry', '--key' => 'app.widget-kinds']);

    require_once $this->appPath.'/Registries/WidgetKindRegistry.php';

    $class = 'App\Registries\WidgetKindRegistry';
    $registry = (new $class)->register('gauge', 'the gauge');
    $declaration = IsRegistry::of($class);

    expect($registry)->toBeInstanceOf(Registry::class)
        ->and($registry)->toBeInstanceOf(Gated::class)
        ->and(class_uses($registry))->toContain(ForwardsToRegistryStore::class)
        ->and($declaration?->root)->toBe('app.widget-kinds')
        ->and($declaration?->onKeyDuplicate)->toBe(OnKeyDuplicate::Supersede)
        ->and($registry->resolve('gauge'))->toBe('the gauge')
        ->and($registry->tryResolve('absent'))->toBeNull()
        ->and(array_map('strval', $registry->keys()))->toBe(['app.widget-kinds.gauge']);
});

it('is described by the bake and filtered by the index authorizer', function () {
    config(['popcorn.bake.paths' => [$this->appPath]]);
    makeRegistry(['name' => 'InvoiceKindRegistry', '--key' => 'app.invoice-kinds']);

    require_once $this->appPath.'/Registries/InvoiceKindRegistry.php';

    app(BakedRegistryManifest::class)->clear();
    TestEnvironmentBake::forget();
    app()->forgetInstance(RegistryIndex::class);

    $index = app(RegistryIndex::class);

    expect($index->pending())->toHaveKey('app.invoice-kinds');

    $registry = $index->resolve('app.invoice-kinds');
    $registry->register('open', 'o')->register('closed', 'c', ability: 'see-closed');

    $index->authorizeWith(denyEverything());

    expect($registry)->toBeInstanceOf('App\Registries\InvoiceKindRegistry')
        ->and($registry->has('closed'))->toBeFalse()
        ->and($registry->has('open'))->toBeTrue()
        ->and($registry->unfiltered()->has('closed'))->toBeTrue();
});

it('prints the beam-docs scaffold line and never calls it, even where a command of that name is registered', function () {
    // The tier rule, held down: a rushing-tier package cannot depend on beam, so the scaffolder is
    // printed, never run. A command registered under its name must see no call at all.
    $scaffold = new class extends Command
    {
        protected $signature = 'splicewire:beam:docs:registries:scaffold {package?} {--root=*}';

        public int $calls = 0;

        public function handle(): int
        {
            $this->calls++;

            return self::SUCCESS;
        }
    };
    Artisan::registerCommand($scaffold);

    [$status, $output] = makeRegistry(['name' => 'ReportFormatRegistry', '--key' => 'app.report-formats']);

    expect(MakeRegistryCommand::SCAFFOLD_COMMAND)->toBe('splicewire:beam:docs:registries:scaffold')
        ->and($status)->toBe(0)
        ->and($scaffold->calls)->toBe(0)
        ->and($output)->toContain(sprintf(
            'Where splicewire/laravel-beam-docs is installed, its scaffolder writes that page: php artisan %s %s --root=app.report-formats',
            MakeRegistryCommand::SCAFFOLD_COMMAND,
            InstalledVersions::getRootPackage()['name'],
        ));
});

it('says where the docs page goes', function () {
    [$status, $output] = makeRegistry(['name' => 'ReportFormatRegistry', '--key' => 'app.report-formats']);

    expect($status)->toBe(0)
        ->and($output)->toContain('docs/registries/app.report-formats.mdx')
        ->and($output)->toContain('docs/registries/manifest.json');
});

it('prints the binding, the bake and the conformance-artifact lines', function () {
    [, $output] = makeRegistry(['name' => 'ReportFormatRegistry', '--key' => 'app.report-formats']);

    expect($output)->toContain('$this->app->singleton(\App\Registries\ReportFormatRegistry::class);')
        ->and($output)->toContain('php artisan popcorn:registries:cache')
        ->and($output)->toContain('php artisan splicewire:beam:registry-conformance --write');
});

it('refuses a missing or illegal root, and writes nothing', function (array $key, string $message) {
    [$status, $output] = makeRegistry(['name' => 'ReportFormatRegistry'] + $key);

    expect($status)->toBe(1)
        ->and($output)->toContain($message)
        ->and(File::exists($this->appPath.'/Registries/ReportFormatRegistry.php'))->toBeFalse();
})->with([
    'missing' => [[], 'Pass the root the registry owns'],
    'uppercase' => [['--key' => 'App.Formats'], 'is not a legal registry key'],
    'slash' => [['--key' => 'app/formats'], 'is not a legal registry key'],
]);

it('accepts a mirrored underscore with a warning', function () {
    [$status, $output] = makeRegistry(['name' => 'ReportFormatRegistry', '--key' => 'app.report_formats']);

    expect($status)->toBe(0)
        ->and($output)->toContain('written in kebab-case');
});

it('refuses to overwrite an existing class unless --force', function () {
    makeRegistry(['name' => 'ReportFormatRegistry', '--key' => 'app.report-formats']);

    [$again] = makeRegistry(['name' => 'ReportFormatRegistry', '--key' => 'app.report-formats']);
    [$forced] = makeRegistry(['name' => 'ReportFormatRegistry', '--key' => 'app.report-formats', '--force' => true]);

    expect($again)->toBe(1)
        ->and($forced)->toBe(0);
});
