<?php

namespace Rushing\Popcorn\Runner\Exceptions;

/** The value channel was non-JSON or breached the hard cap — refused rather than truncated to a lie. */
class MalformedOutput extends RunFailed {}
