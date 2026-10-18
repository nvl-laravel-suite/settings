<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/** Ordinary Laravel actor admitted by the fixture host policy. */
final class OwnershipActor extends User
{
    protected $table = 'settings_test_actors';

    protected $guarded = [];

    public $timestamps = false;
}
