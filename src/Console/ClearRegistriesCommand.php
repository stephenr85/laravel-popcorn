<?php

namespace Rushing\Popcorn\Laravel\Console;

use Illuminate\Console\Command;
use Rushing\Popcorn\Laravel\Baking\BakedRegistryManifest;

/**
 * `php artisan popcorn:registries:clear` — remove the baked membership list.
 *
 * ⚠️ **A cleared host does not fall back to anything.** After registry-kernel ticket 73's cutover there
 * are no hand-written `describe()` calls left, so the next boot has no membership at all and every read
 * raises `UnbakedRegistryIndex` — deliberately, per D3.2: absent must be loud, never empty. Clear this
 * only as the first half of a rebake. Hooked into `optimize:clear` for the same reason
 * `bootstrap/cache/packages.php` is.
 */
class ClearRegistriesCommand extends Command
{
    protected $signature = 'popcorn:registries:clear';

    protected $description = 'Remove the baked registry membership list (rebake with popcorn:registries:cache).';

    public function handle(BakedRegistryManifest $manifest): int
    {
        $manifest->clear();

        $this->components->info('Removed the baked registry membership list.');
        $this->components->warn(
            'Nothing falls back to a live scan: until `popcorn:registries:cache` runs, every membership '
                .'read raises UnbakedRegistryIndex rather than reporting an empty estate.'
        );

        return self::SUCCESS;
    }
}
