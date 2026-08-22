<?php

namespace Whilesmart\WebSearch\Enums;

enum RoutingMode: string
{
    case Merge = 'merge';
    case Waterfall = 'waterfall';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
