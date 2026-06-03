<?php

namespace App\Console\Commands;

use App\Services\RecommenderDatabaseSync;
use Illuminate\Console\Command;

class SyncRecommenderDatabase extends Command
{
    protected $signature = 'recommender:sync';

    protected $description = 'Sync Sukoon apartments into recommender/recommender.db for ML ranking';

    public function handle(RecommenderDatabaseSync $sync): int
    {
        $this->info('Syncing recommender database at: ' . $sync->dbPath());

        if ($sync->sync()) {
            $this->info('Recommender database synced successfully.');
            return self::SUCCESS;
        }

        $this->warn('Sync finished with no open apartments or an error (check logs).');
        return self::FAILURE;
    }
}
