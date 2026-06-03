<?php

return [

    'enabled' => env('WORKER_AUTO_SCALING', true),

    'poll_interval' => (int) env('WORKER_SCALING_POLL_INTERVAL', 5),

    'min_workers' => (int) env('WORKER_SCALING_MIN', 3),
    'max_workers' => (int) env('WORKER_SCALING_MAX', 15),

    'scale_up_threshold' => (int) env('WORKER_SCALING_UP_THRESHOLD', 2),
    'scale_down_delay' => (int) env('WORKER_SCALING_DOWN_DELAY', 120),

    'war_room_boost' => (int) env('WORKER_SCALING_WAR_ROOM_BOOST', 6),

    'general_min' => (int) env('WORKER_SCALING_GENERAL_MIN', 2),
    'general_max' => (int) env('WORKER_SCALING_GENERAL_MAX', 5),

    'supervisor_config_path' => env('WORKER_SUPERVISOR_CONFIG', '/etc/supervisor/conf.d/supervisord-worker.conf'),
];
