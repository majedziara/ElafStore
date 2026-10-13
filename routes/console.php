<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('store:maintain')->everyFiveMinutes()->withoutOverlapping(20);
Schedule::command('auth:clear-resets')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
