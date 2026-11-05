<?php

namespace Rushing\Popcorn\Laravel\Console;

use Composer\InstalledVersions;
use Illuminate\Console\GeneratorCommand;
use Rushing\Popcorn\Laravel\Baking\BakedRegistryManifest;
use Rushing\Popcorn\Registries\ForwardsToRegistryStore;
use Rushing\Popcorn\Registries\Key;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
 * ## The docs page: printed, never written or called
 *
 * The page writer is `splicewire/laravel-beam-docs`' {@see SCAFFOLD_COMMAND}. This command prints that
 * command line, for this root package and this one root, labelled as available where beam-docs is
 * installed. It never calls it, not even where it is registered: a rushing-tier package cannot depend on
 * beam, and a guarded call by name would still put a splicewire-tier command inside this one. It never
 * writes a page itself either, because a second page writer would be a second renderer for a facts block
 * that the docs check compares byte for byte.
 *
 * ## What it prints instead of doing
 *
 * It edits no provider and runs no other command. It prints where the docs page goes, the singleton
 * binding, the bake that describes the class into the index (`popcorn:registries:cache`), and the
 * command that records the class in the committed registry conformance artifact
 * ({@see CONFORMANCE_COMMAND}, which ships with `splicewire/laravel-beam`).
 */
class MakeRegistryCommand extends GeneratorCommand
{
    /** The registry docs scaffolder in `splicewire/laravel-beam-docs`. Printed, never called (tier rule). */
    public const SCAFFOLD_COMMAND = 'splicewire:beam:docs:registries:scaffold';

    /** The command that writes the committed registry-conformance artifact, in `splicewire/laravel-beam`. */
    public const CONFORMANCE_COMMAND = 'splicewire:beam:registry-conformance';

    protected $name = 'popcorn:make:registry';

    protected $description = 'Create a Gated registry class: #[IsRegistry], a held BasicRegistry, and the ForwardsToRegistryStore forwarders.';

    protected $type = 'Registry';

    /** The validated root for this run, substituted into the stub. */
    private string $root = '';

    /** Whether this run refused (an illegal root, or a class that exists without `--force`). */
    private bool $refused = false;

    /**
     * `?bool`, the framework's contract for this method; the exit code is {@see execute()}'s. The framework
     * casts this return value to int, which would turn a refusal (`false`) into exit 0.
     *
     * The refusal flag is reset here, once per invocation: Laravel keeps one command instance per kernel, so
     * an earlier call's refusal must not decide this one.
     */
    public function handle(): ?bool
    {
        $this->refused = false;

        $root = $this->rootKey();

        if ($root === null) {
            $this->refused = true;

            return false;
        }

        $this->root = $root;

        if (parent::handle() === false) {
            $this->refused = true;

            return false;
        }

        $this->nextSteps($this->qualifyClass($this->getNameInput()), $root);

        return null;
    }

    /** A refusal exits 1; otherwise the framework's own exit code stands. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = parent::execute($input, $output);

        return $this->refused ? self::FAILURE : $status;
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

    /** Every follow-up is printed; nothing here runs another command or edits another file. */
    private function nextSteps(string $class, string $root): void
    {
        $this->components->info('Next:');
        $this->line(sprintf(
            '  Document it: docs/registries/%s.mdx, or list `%s` in docs/registries/manifest.json; `popcorn:registries:docs` checks either.',
            $root,
            $root,
        ));
        $this->line(sprintf(
            '  Where splicewire/laravel-beam-docs is installed, its scaffolder writes that page: php artisan %s %s --root=%s',
            self::SCAFFOLD_COMMAND,
            $this->rootPackage() ?? '<vendor/name>',
            $root,
        ));
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
