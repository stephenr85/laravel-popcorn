<?php

namespace Rushing\Popcorn\Laravel\Console;

use Composer\InstalledVersions;
use Illuminate\Console\GeneratorCommand;
use Rushing\Popcorn\Laravel\Baking\BakedRegistryManifest;
use Rushing\Popcorn\Registries\ForwardsToRegistryStore;
use Rushing\Popcorn\Registries\Key;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php artisan popcorn:make:registry {name} --key=<root>` (make-surface 05): a new registry class that is
 * already declared, gated and forwarding.
 *
 * The class it writes carries `#[IsRegistry]` with its root and duplicate policy written out (an
 * unwritten policy is a `RegistryConformanceAudit` finding), implements `Gated` and `Registry`, holds a
 * `BasicRegistry` field, and reads through {@see ForwardsToRegistryStore}. What is left in the class is
 * `register()`, `store()` and whatever vocabulary its author adds.
 *
 * ## Laravel's generator, not beam's
 *
 * This package is rushing-tier and cannot depend on `splicewire/laravel-beam`, so it extends
 * `GeneratorCommand` directly rather than beam's `ParticleGeneratorCommand` (make-surface MAP, Notes).
 * The stub is `stubs/registry.stub`, overridable at the same path under the host's base path.
 *
 * ## The docs page, through the scaffolder that owns it
 *
 * The page writer is `splicewire/laravel-beam-docs`' {@see SCAFFOLD_COMMAND}. Where a host has it
 * registered, this command runs it for the root package and this one root. Elsewhere it says what to
 * write and where. It never writes a page itself: a second page writer would be a second renderer for a
 * facts block that the docs check compares byte for byte.
 *
 * ## What it prints instead of doing
 *
 * It edits no provider. It prints the singleton binding, the bake that describes the class into the index
 * (`popcorn:registries:cache`), and the command that records the class in the committed registry
 * conformance artifact ({@see CONFORMANCE_COMMAND}, which ships with `splicewire/laravel-beam`).
 */
class MakeRegistryCommand extends GeneratorCommand
{
    /** The registry docs scaffolder in `splicewire/laravel-beam-docs`. Called by name, only where it is registered. */
    public const SCAFFOLD_COMMAND = 'splicewire:beam:docs:registries:scaffold';

    /** The command that writes the committed registry-conformance artifact, in `splicewire/laravel-beam`. */
    public const CONFORMANCE_COMMAND = 'splicewire:beam:registry-conformance';

    protected $name = 'popcorn:make:registry';

    protected $description = 'Create a Gated registry class: #[IsRegistry], a held BasicRegistry, and the ForwardsToRegistryStore forwarders.';

    protected $type = 'Registry';

    /** The validated root for this run, substituted into the stub. */
    private string $root = '';

    /** `int`, not the framework's `?bool`: a refusal must exit non-zero. */
    public function handle(): int
    {
        $root = $this->rootKey();

        if ($root === null) {
            return self::FAILURE;
        }

        $this->root = $root;

        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $documented = $this->document($root);

        $this->nextSteps($this->qualifyClass($this->getNameInput()));

        return $documented ? self::SUCCESS : self::FAILURE;
    }

    protected function getStub(): string
    {
        return $this->resolveStubPath('stubs/registry.stub');
    }

    /**
     * The package stub, unless the host has published its own at the same relative path, which is the
     * framework's own `resolveStubPath` convention.
     */
    protected function resolveStubPath(string $stub): string
    {
        $custom = $this->laravel->basePath(trim($stub, '/'));

        return file_exists($custom) ? $custom : dirname(__DIR__, 2).'/'.trim($stub, '/');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Registries';
    }

    protected function buildClass($name): string
    {
        return str_replace('{{ root }}', $this->root, parent::buildClass($name));
    }

    /**
     * @return list<array{0: string, 1: string|null, 2: int, 3: string}>
     */
    protected function getOptions(): array
    {
        return [
            ['key', null, InputOption::VALUE_REQUIRED, 'The root key the registry owns, dotted lowercase-kebab (app.report-formats)'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the class if it already exists'],
        ];
    }

    /** The `--key` root, or null after saying why it cannot be one. */
    private function rootKey(): ?string
    {
        $key = $this->option('key');
        $key = is_string($key) ? trim($key) : '';

        if ($key === '') {
            $this->components->error('Pass the root the registry owns: --key=app.report-formats.');

            return null;
        }

        if (Key::tryParse($key) === null) {
            $this->components->error(sprintf(
                '`%s` is not a legal registry key. A key is dot-separated segments of lowercase letters and '
                    .'digits, with words joined by `-`.',
                $key,
            ));

            return null;
        }

        if (preg_match('/[_:]/', $key) === 1) {
            $this->components->warn(sprintf(
                '`%s` is legal, but a new root is written in kebab-case. `_` and `:` belong to segments '
                    .'mirrored from outside the registry (rushing/php-popcorn docs/agents/registry-keys.convention.md).',
                $key,
            ));
        }

        return $key;
    }

    /** Run the docs scaffolder for this root where one is registered; false when it ran and failed. */
    private function document(string $root): bool
    {
        $package = $this->rootPackage();

        if ($package !== null && $this->getApplication()?->has(self::SCAFFOLD_COMMAND) === true) {
            $status = $this->call(self::SCAFFOLD_COMMAND, ['package' => $package, '--root' => [$root]]);

            if ($status !== self::SUCCESS) {
                $this->components->error(sprintf('`%s` exited %d, so the docs page for `%s` was not written.', self::SCAFFOLD_COMMAND, $status, $root));

                return false;
            }

            return true;
        }

        $this->components->warn(sprintf(
            'No registry docs scaffolder is registered here (`%s` ships with splicewire/laravel-beam-docs). '
                .'Write docs/registries/%s.mdx, or list `%s` in docs/registries/manifest.json; '
                .'`popcorn:registries:docs` checks either.',
            self::SCAFFOLD_COMMAND,
            $root,
            $root,
        ));

        return true;
    }

    private function nextSteps(string $class): void
    {
        $this->components->info('Next:');
        $this->line(sprintf('  Bind it once, in a service provider\'s register(): $this->app->singleton(\\%s::class);', ltrim($class, '\\')));
        $this->line(sprintf('  Describe it into the index by baking the membership list: php artisan %s', BakedRegistryManifest::COMMAND));
        $this->line(sprintf('  Record it in the registry-conformance artifact (splicewire/laravel-beam): php artisan %s --write', self::CONFORMANCE_COMMAND));
    }

    private function rootPackage(): ?string
    {
        if (! class_exists(InstalledVersions::class)) {
            return null;
        }

        $name = InstalledVersions::getRootPackage()['name'];

        return $name === '' ? null : $name;
    }
}
