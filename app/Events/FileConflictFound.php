<?php

namespace App\Events;

use App\File;
use Illuminate\Queue\SerializesModels;

class FileConflictFound
{
    use SerializesModels;

    public $file;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(File $file)
    {
        $this->file = $file;
    }
}
