<?php

namespace App\Console\Commands;

use App\Models\ResourceLock;
use Illuminate\Console\Command;

class LocksPruneExpired extends Command
{
    protected $signature = 'locks:prune-expired';

    protected $description = 'Delete expired resource locks';

    public function handle(): int
    {
        $deleted = ResourceLock::query()
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("Pruned {$deleted} expired lock(s).");

        return self::SUCCESS;
    }
}
