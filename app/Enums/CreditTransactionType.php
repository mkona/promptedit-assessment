<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case Purchase = 'purchase';
    case Hold = 'hold';
    case HoldRelease = 'hold_release';
    case Charge = 'charge';
}
