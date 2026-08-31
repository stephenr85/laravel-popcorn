<?php

namespace Rushing\Popcorn\Laravel\Baking;

use Illuminate\Contracts\Foundation\Application;

/**
 * The membership list a TESTBENCH gets, because a testbench has no build step to have run one.
 *
 * Registry-kernel ticket 73 **D6**, which corrects D5. The correction is worth stating because the
 * distinction it draws is the whole justification for this class existing at all:
 *
 * | state | what it is | behaviour |
 * |---|---|---|
 * | artifact absent in a **built host** | **a broken build** — someone deployed without baking | **THROW** (D3.2, unchanged) |
 * | artifact absent in an **unbuilt environment** | nothing has built anything yet; there is no build to be broken | **supply it — this class** |
 *
 * This is what `RefreshDatabase` does for migrations: a test environment does not run your deploy
 * pipeline, so the harness provides the build product rather than failing on its absence. It does **not**
 * soften D3.2's signal. That signal is *"a deployed host that forgot to bake must not answer quietly"*,
 * and it is untouched — see {@see \Rushing\Popcorn\Laravel\PopcornServiceProvider} for the branch, and
 * `PopcornServiceProvider`'s production-throw test, which is now the only thing exercising it.
 *
 * ## Why an automatic bake and not an opt-in trait
 *
 * D5 ruled the opposite — an explicit bake each suite calls — and its own escalation clause overturned
 * it. Measured across the estate: **29 repos and 109 failing cases**, of which only **11** are
 * *"my provider describes me"* assertions. The other two thirds are beam's
 * `RegistryConformanceAudit`/`Command` tests, whose **input** is the live index, and beam-workflows'
 * dispatch tests, which merely route through it. Those make no claim about the bake and have no
 * assertion to re-point: they need the index populated as a **fixture**. An opt-in call would therefore
 * have landed in ~29 of ~30 harnesses — a hand-maintained per-repo list, which is
 * `getPackageProviders()` again, the shape AGENTS.md documents rotting silently at 53 packages.
 *
 * ## It fires on ABSENT, never on DISAGREES
 *
 * A built host whose artifact is **stale or wrong** is a different failure and must not be papered over
 * here: this is reached only when `BakedRegistryManifest::read()` returns `null`, i.e. the file is not
 * there. A present artifact always wins, in every environment, however wrong it is — that is a
 * conformance question the audit owns, not a bake question.
 *
 * ## Cached per PROCESS
 *
 * A testbench boots the application once per test. Scanning the estate on each boot, across 29 suites,
 * is a tax paid thousands of times for an answer that cannot change within a process — the source tree
 * is not being edited mid-run. So the scan is memoized statically and {@see forget()} exists for the
 * one test that needs to prove the memo is real.
 */
class TestEnvironmentBake
{
    /**
     * @var array<string, array{class: class-string, by: string}>|null
     */
    private static ?array $memo = null;

    public function __construct(private Application $app) {}

    /**
     * Scan the estate for declared registries, once per process.
     *
     * @return array<string, array{class: class-string, by: string}>
     */
    public function map(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        // ⚠️ The scan's SOURCE PRE-FILTER is mandatory here rather than merely helpful, and this is the
        // environment that proves it. A testbench composes a PARTIAL estate — a package's own suite has
        // its `require-dev` and nothing else — which is exactly where a class whose parent or trait is
        // absent lives. A missing parent raises a catchable `Error`; a missing TRAIT is an uncatchable
        // `E_COMPILE_ERROR` that killed three `~/Herd` hosts earlier in this ticket. `DeclaredRegistryScan`
        // filters on the attribute's short name in the file's SOURCE before autoloading anything, which
        // is what makes scanning safe here at all.
        return self::$memo = $this->app->make(DeclaredRegistryScan::class)->run()['map'];
    }

    /** Drop the per-process memo. For tests about the memo itself; nothing in production calls this. */
    public static function forget(): void
    {
        self::$memo = null;
    }
}
