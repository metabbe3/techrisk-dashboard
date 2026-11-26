<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Doha\DohaSyncService;
use Illuminate\Console\Command;

class SyncDohaTickets extends Command
{
    protected $signature = 'doha:sync-tickets
        {--full : Full re-read — re-fetch every ticket and drop local rows DOHA no longer returns}';

    protected $description = 'Mirror DOHA fund-loss tickets into the local read-only mirror';

    public function handle(DohaSyncService $sync): int
    {
        $result = $sync->sync((bool) $this->option('full'));

        $this->info($result['message']);

        return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
