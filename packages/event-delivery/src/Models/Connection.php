<?php

namespace TrafficOps\EventDelivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Connection extends Model
{
    use SoftDeletes;

    protected $table = 'connections';

    protected $fillable = ['name', 'type', 'configuration', 'active'];

    protected $hidden = ['configuration'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'configuration' => 'encrypted:array'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
