<?php

namespace App\Enums;

enum Status: string
{
    case LIVE = 'LIVE';
    case DIE = 'DIE';
    case ERROR = 'ERROR';
    case RECHECK = 'RECHECK';
}
