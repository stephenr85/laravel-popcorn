<?php

namespace Rushing\Popcorn\Runner\Exceptions;

/** The resolved substrate was missing/unrunnable at run time (e.g. bwrap absent, engine-version mismatch). */
class SubstrateUnavailable extends RunFailed {}
