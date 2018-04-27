<?php

namespace App;

use Jenssegers\Mongodb\Eloquent\Model as Eloquent;

class File extends Eloquent
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['app', 'file'];

    public function conflicts()
    {
        return $this->hasMany('App\Conflict');
    }
}
