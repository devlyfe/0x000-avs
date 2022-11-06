<?php

namespace App\Objects;

use Spatie\DataTransferObject\DataTransferObject;

class CardDto extends DataTransferObject
{
    public string $number;
    public string $month;
    public string $year;
    public string $securityCode;
    public string $type;
}
