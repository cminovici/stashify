<?php

namespace App;

use Jenssegers\Mongodb\Eloquent\Model as Eloquent;

class Conflict extends Eloquent
{
    public function file()
    {
        return $this->belongsTo('App\File');
    }
}
