<?php

namespace Whilesmart\WebSearch\Enums;

enum SnapshotFailure: string
{
    case Blocked = 'blocked';
    case Timeout = 'timeout';
    case Unreachable = 'unreachable';
    case Refused = 'refused';
    case HttpStatus = 'http_status';
    case Unavailable = 'unavailable';
    case Unknown = 'unknown';
}
