<?php

namespace Scannr\Enums;

enum Severity: string
{
    case CRITICAL = 'critical';
    case WARNING = 'warning';
    case INFO = 'info';
}
