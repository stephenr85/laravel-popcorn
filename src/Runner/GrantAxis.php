<?php

namespace Rushing\Popcorn\Runner;

/**
 * The capability axes a {@see Grant} carries. Used two ways:
 *  - on a {@see GrantRequest} to flag which axes are `required` (deny ⇒ fail-closed)
 *    vs. optional (deny ⇒ degrade), per popcorn-runner ticket 05;
 *  - on a {@see Result} to name the axis of a `GrantDenied` security event
 *    ({@see Result::$deniedAxis}) so an audit log reads "tried to write /etc", not a generic 137.
 */
enum GrantAxis: string
{
    case Paths = 'paths';
    case Net = 'net';
    case Env = 'env';
    case Limits = 'limits';
    case Seccomp = 'seccomp';
}
