<?php

namespace Rushing\Popcorn\Runner\Exceptions;

/** The run exceeded a cpu/memory ceiling and was killed by the substrate. */
class ResourceLimitExceeded extends RunFailed {}
