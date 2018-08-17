<?php

namespace App;

use Jenssegers\Mongodb\Eloquent\Model as Eloquent;

class Conflict extends Eloquent
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['committer', 'branch', 'link'];

    public function file()
    {
        return $this->belongsTo('App\File');
    }
}
