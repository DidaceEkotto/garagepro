<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('garagepro:process-subscriptions')->dailyAt('07:00');
