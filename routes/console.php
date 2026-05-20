<?php

use App\Jobs\ScheduleDispatcher;
use App\Jobs\RefreshTokenIntegrationsJob;
use Illuminate\Support\Facades\Schedule;

/**
 * Jobs agendados para serem executados em todos os servidores
 */
Schedule::job(new ScheduleDispatcher('finish_calls_24h'))
        ->dailyAt('00:30')
        ->onOneServer();
        
Schedule::job(new ScheduleDispatcher('finish_order_access'))
        ->dailyAt('00:15')
        ->onOneServer();
        
Schedule::job(new ScheduleDispatcher('update_s3_metrics'))
        ->dailyAt('03:00')
        ->onOneServer();
        
Schedule::job(new ScheduleDispatcher('archive_finished_tasks'))
        ->dailyAt('00:10')
        ->onOneServer();
        
Schedule::job(new ScheduleDispatcher('refresh_mercado_livre'))
        ->cron('0 3,9,15,21 * * *')
        ->onOneServer();

Schedule::job(new ScheduleDispatcher('emails:process-actions'))
        ->everyMinute()
        ->withoutOverlapping()
        ->onOneServer();

Schedule::job(new ScheduleDispatcher('email-inbox:import'))
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->onOneServer();

// Lembretes de compromissos para começar em 10 minutos.
Schedule::job(new ScheduleDispatcher('notify_commitments_10m', [
            'minutes_before' => 10,
        ]))
        ->everyMinute()
        ->onOneServer();

Schedule::job(new RefreshTokenIntegrationsJob())
        ->dailyAt('01:00')
        ->onOneServer();
