<?php

namespace Rushing\Popcorn\Laravel\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Rushing\Popcorn\Registries\Docs\RegistryDocsCheck;

/**
 * `php artisan popcorn:registries:docs [path]`: the package-tier registry docs check, run from a
 * Laravel package or host (registry-docs ticket 04).
 *
 * The check itself is framework-free and lives in `rushing/php-popcorn`
 * ({@see RegistryDocsCheck}); a package normally runs it as one test in `composer test`. This
 * command adds no rule of its own. It makes the same check reachable from artisan, for a package
 * testbench, a CI step that prefers a command over a test, or a host app whose `docs/registries/`
 * exempts its app-local roots.
 *
 * - `path` is the package root to check. It defaults to the root package's install path, which is
 *   right in a host and in a testbench alike.
 * - Inactive without `docs/registries/` (the describe-gate ratchet), and inactive passes.
 * - Its scope is that one package's own declarations, never a host or fleet population. The
 *   host-tier coverage audit is beam-docs' doctor check.
 * - Like the check, it never writes.
 */
class RegistryDocsCommand extends Command
{
    protected $signature = 'popcorn:registries:docs
        {path? : The package root to check (default: the root package)}
        {--json : Print the report as JSON}';

    protected $description = 'Check that every #[IsRegistry] root a package declares has a page, manifest pointer or exemption (read-only).';

    public function handle(): int
    {
        $path = $this->argument('path') ?: $this->rootPackage();

        if (! is_string($path) || ! is_dir($path)) {
            $this->error('No package root to check: pass a path.');

            return self::FAILURE;
        }

        $report = RegistryDocsCheck::forPackage((string) realpath($path))->run();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'package' => $report->package,
                'active' => $report->active,
                'passes' => $report->passes(),
                'coverage' => $report->coverage,
                'failures' => $report->failures(),
                'facts_checked' => $report->factsChecked,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($report->failures() as $failure) {
                $this->error($failure);
            }
            $report->passes() ? $this->info($report->summary()) : $this->warn($report->summary());
        }

        return $report->passes() ? self::SUCCESS : self::FAILURE;
    }

    private function rootPackage(): ?string
    {
        return class_exists(InstalledVersions::class) ? InstalledVersions::getRootPackage()['install_path'] : null;
    }
}
