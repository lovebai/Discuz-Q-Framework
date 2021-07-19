<?php
namespace Discuz\Foundation;
use Illuminate\Database\Events\QueryExecuted;

class SqlProfileListener
{
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
     * @param  ExampleEvent  $event
     * @return void
     */
    public function handle(QueryExecuted $event)
    {
        $temp = $GLOBALS["mysql_time"];
        $GLOBALS["mysql_time"] = $temp + $event->time;
    }
}