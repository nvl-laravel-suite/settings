<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Native host owner without an NVL tenancy or authentication package. */
final class OwnershipCompany extends Model
{
    protected $table = 'settings_test_companies';

    protected $guarded = [];

    public $timestamps = false;
}
