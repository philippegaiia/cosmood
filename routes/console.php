<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('locks:prune-expired')->hourly();
