<?php

namespace App\Enums;

enum Urgency: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
