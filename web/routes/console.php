<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('campuslf:archive-posts')->daily();
Schedule::command('campuslf:expire-handovers')->everyMinute()->withoutOverlapping();
