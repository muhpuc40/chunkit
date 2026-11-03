<?php

declare(strict_types=1);

namespace Muhpuc40\Chunkit\Console;

use Illuminate\Console\Command;
use Muhpuc40\Chunkit\Services\ChunkManager;

/**
 * Deletes unfinished uploads that have passed their expiry (ttl_hours after the
 * last activity): the database row and the chunk directory.
 *
 * This package does not schedule itself. The host app adds the schedule entry,
 * which the README shows.
 */
class PruneAbandonedUploads extends Command
{
    protected $signature = 'chunkit:prune
        {--dry-run : Report what would be deleted without deleting anything}';

    protected $description = 'Delete abandoned chunked uploads (database rows and chunk directories)';

    public function handle(ChunkManager $manager): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $manager->pruneAbandoned($dryRun);

        if ($dryRun) {
            $this->info("Dry run: {$result['deleted']} abandoned upload(s) would be deleted.");

            return self::SUCCESS;
        }

        $this->info("Deleted {$result['deleted']} abandoned upload(s).");

        if ($result['failed'] > 0) {
            $this->error("{$result['failed']} upload(s) could not be deleted. See the log for details.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
