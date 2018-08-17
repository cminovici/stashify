<?php

namespace App\Listeners;

use App\Events\FileConflictFound;
use App\Mail\FileConflict;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendConflictNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  FileConflictFound $event
     * @return void
     */
    public function handle(FileConflictFound $event)
    {
        $this->release(5);

        $to = $event->file->conflicts->map(function ($conflict){
            return $conflict->committer;
        });

        Mail::to($to->unique()->values()->all())
            ->send(new FileConflict($event->file));
    }

    /**
     * Handle a job failure.
     *
     * @param  FileConflictFound $event
     * @param  \Exception $exception
     * @return void
     */
    public function failed(FileConflictFound $event, $exception)
    {
        dd($exception);
    }
}
