<?php

namespace PhilipAnnis\HttpRemember;

/**
 * Distinguish an omitted operation from an explicitly supplied null.
 *
 * @internal
 */
enum HttpRememberOperation
{
    case Automatic;
}
