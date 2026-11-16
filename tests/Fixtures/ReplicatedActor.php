<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/** Laravel actor stored on a connection whose read PDO can lag behind its write PDO. */
final class ReplicatedActor extends User
{
    public const string Connection = 'settings_replicated_actors';

    protected $connection = self::Connection;

    protected $table = 'settings_test_actors';

    protected $guarded = [];

    public $timestamps = false;
}
