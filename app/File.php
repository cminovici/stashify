<?php

namespace App;

use Jenssegers\Mongodb\Eloquent\Model as Eloquent;

class File extends Eloquent
{
    public function conflicts()
    {
        return $this->hasMany('App\Conflict');
    }
}
