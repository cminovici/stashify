<?php

namespace App\Http\Controllers;

use App\Services\FileConflict;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    private $fileConflict;

    public function __construct(FileConflict $fileConflict)
    {
        $this->fileConflict = $fileConflict;
    }

    public function index()
    {
        $conflicts = $this->fileConflict->getFileConflicts();

        if ($conflicts) {
            $this->fileConflict->parseAndSaveResult($conflicts);
        }
    }
}
