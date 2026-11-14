<?php

namespace Tests\Fixtures\Listing;

use Illuminate\Database\Eloquent\Model;

class ListingTestSession extends Model
{
    protected $table = 'sessions';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
