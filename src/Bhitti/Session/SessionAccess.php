<?php

declare(strict_types=1);

namespace Bhitti\Session;

enum SessionAccess
{
    case READ;
    case WRITE;
}
