<?php

namespace App\Models;

use Jenssegers\Mongodb\Eloquent\Model as Moloquent;

class Conflict extends Moloquent
{
    protected $collection = 'conflicts_collection';
}
