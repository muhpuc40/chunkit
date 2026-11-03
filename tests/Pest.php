<?php

declare(strict_types=1);

use Muhpuc40\Chunkit\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case
|--------------------------------------------------------------------------
|
| Every Pest test boots the Testbench application defined in TestCase.
| Class-based tests (such as RoutesCustomPrefixTest) extend it themselves.
|
*/

uses(TestCase::class)->in('Feature', 'Unit');
