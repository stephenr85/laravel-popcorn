<?php

namespace Rushing\Popcorn\Runner\Exceptions;

use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\GrantAxis;
use Rushing\Popcorn\Runner\Result;

/**
 * The run touched — or was refused — a capability outside its effective {@see Grant}.
 * A **security event**, not a generic failure: it carries the denied axis + target off the Result so an
 * audit log reads "tried to write /etc" rather than a bare exit 137. Unlike the capability failures,
 * a `GrantDenied` propagates *past* a strategy ladder (fail-loud) instead of demoting.
 */
class GrantDenied extends RunFailed
{
    public ?GrantAxis $deniedAxis;

    public ?string $deniedTarget;

    public function __construct(Result $result, ?string $message = null)
    {
        parent::__construct($result, $message);

        $this->deniedAxis = $result->deniedAxis;
        $this->deniedTarget = $result->deniedTarget;
    }
}
