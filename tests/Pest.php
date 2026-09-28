<?php

use Whilesmart\WebSearch\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

function fixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
}
