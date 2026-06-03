<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;

class AutoScaleWorkers extends Command
{
    protected $signature = 'workers:auto-scale';

    protected $description = 'Monitor queue depth and auto-scale Supervisor worker processes';

    private ?string $configPath = null;

    private array $warRoomQueues = ['war-room'];

    private array $generalQueues = ['default', 'document-conversion', 'api-audit'];

    private ?float $idleSince = null;

    public function handle(): int
    {
        if (! config('worker-scaling.enabled', true)) {
            $this->info('Auto-scaling disabled.');

            return self::SUCCESS;
        }

        $this->configPath = config('worker-scaling.supervisor_config_path', '/etc/supervisor/conf.d/supervisord-worker.conf');
        $pollInterval = config('worker-scaling.poll_interval', 5);

        $this->info('Auto-scaler started. Polling every '.$pollInterval.'s');

        while (true) {
            try {
                $this->tick();
            } catch (\Throwable $e) {
                $this->error('Tick error: '.$e->getMessage());
            }

            sleep($pollInterval);
        }
    }

    private function tick(): void
    {
        $warRoomDepth = $this->getTotalDepth($this->warRoomQueues);
        $generalDepth = $this->getTotalDepth($this->generalQueues);

        $currentWarRoom = $this->readNumprocs('war-room-worker');
        $currentGeneral = $this->readNumprocs('general-worker');

        if ($currentWarRoom === null || $currentGeneral === null) {
            return;
        }

        $desiredWarRoom = $this->calculateWarRoomWorkers($warRoomDepth, $currentWarRoom);
        $desiredGeneral = $this->calculateGeneralWorkers($generalDepth, $currentGeneral);

        if ($desiredWarRoom !== $currentWarRoom) {
            $this->scaleGroup('war-room-worker', $currentWarRoom, $desiredWarRoom);
        }

        if ($desiredGeneral !== $currentGeneral) {
            $this->scaleGroup('general-worker', $currentGeneral, $desiredGeneral);
        }

        $totalDepth = $warRoomDepth + $generalDepth;
        if ($totalDepth === 0) {
            $this->idleSince ??= microtime(true);
        } else {
            $this->idleSince = null;
        }
    }

    private function calculateWarRoomWorkers(int $depth, int $current): int
    {
        $min = config('worker-scaling.min_workers', 3);
        $max = config('worker-scaling.max_workers', 15);
        $boost = config('worker-scaling.war_room_boost', 6);
        $threshold = config('worker-scaling.scale_up_threshold', 2);
        $downDelay = config('worker-scaling.scale_down_delay', 120);

        if ($depth > 0) {
            $this->idleSince = null;
            $desired = $min + $boost;
            $this->info("war-room depth={$depth}, boost to {$desired}");

            return min($desired, $max);
        }

        if ($this->idleSince !== null) {
            $idleSeconds = microtime(true) - $this->idleSince;
            if ($idleSeconds >= $downDelay) {
                $this->info("Idle for {$downDelay}s, scaling war-room down to {$min}");

                return $min;
            }
        }

        $base = max($min, (int) ceil($depth / $threshold));

        return min(max($base, $min), $max);
    }

    private function calculateGeneralWorkers(int $depth, int $current): int
    {
        $min = config('worker-scaling.general_min', 2);
        $max = config('worker-scaling.general_max', 5);

        if ($depth <= $min) {
            return $min;
        }

        $desired = min($depth, $max);

        return max($desired, $min);
    }

    private function getTotalDepth(array $queues): int
    {
        $prefix = config('database.redis.options.prefix', '');
        $total = 0;

        foreach ($queues as $queue) {
            $key = "{$prefix}queues:{$queue}";
            try {
                $total += (int) Redis::connection()->llen($key);
            } catch (\Throwable $e) {
                $this->warn("Failed to check queue {$queue}: ".$e->getMessage());
            }
        }

        return $total;
    }

    private function readNumprocs(string $groupName): ?int
    {
        if (! file_exists($this->configPath)) {
            $this->error("Config not found: {$this->configPath}");

            return null;
        }

        $content = file_get_contents($this->configPath);
        $pattern = "/\\[program:{$groupName}\\](.*?)\\[program:/s";

        if (! preg_match($pattern, $content, $match)) {
            if (! preg_match("/\\[program:{$groupName}\\](.*)$/s", $content, $match)) {
                $this->warn("Group {$groupName} not found in config");

                return null;
            }
        }

        if (preg_match('/numprocs=(\d+)/', $match[1] ?? $match[0], $numMatch)) {
            return (int) $numMatch[1];
        }

        return null;
    }

    private function scaleGroup(string $groupName, int $from, int $to): void
    {
        $this->info("Scaling {$groupName}: {$from} -> {$to}");

        if (! file_exists($this->configPath)) {
            $this->error("Config not found: {$this->configPath}");

            return;
        }

        $content = file_get_contents($this->configPath);

        $content = preg_replace_callback(
            "/(\\[program:{$groupName}\\].*?numprocs=)(\\d+)/s",
            fn ($m) => $m[1].$to,
            $content
        );

        file_put_contents($this->configPath, $content, LOCK_EX);

        $this->runSupervisorctl('reread');
        $this->runSupervisorctl('update');
    }

    private function runSupervisorctl(string $command): ?string
    {
        $config = $this->configPath;
        $process = Process::fromShellCommandline("supervisorctl -c {$config} {$command}");
        $process->run(timeout: 10);

        $output = trim($process->getOutput());

        if ($process->getExitCode() !== 0) {
            $this->warn("supervisorctl {$command} failed: ".$process->getErrorOutput());

            return null;
        }

        return $output;
    }
}
