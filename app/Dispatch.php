<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Dispatch extends Model
{
    protected $guarded = [];

    public function getRouteKeyName()
    {
        return 'reference_number';
    }

    /**
     * Get the user that owns the dispatch.
     */
    public function user()
    {
        return $this->belongsTo('App\User', 'user_id');
    }

    public function status()
    {
        return $this->belongsTo('App\DispatchStatus');
    }

    public function stops()
    {
        return $this->belongsToMany('App\Warehouse', 'dispatch_stops')->withPivot('id', 'position', 'type_id', 'drop_hooks', 'stale_count', 'tray_count', 'pack_outs', 'roll_offs', 'pallets', 'different')->withTimestamps()->orderBy('position');
    }

    public function stop_types()
    {
        return $this->hasOne('App\DispatchStopType');
    }
}
