<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ShowLogs extends Command
{
    protected $signature = 'logs:show {--lines=50} {--grep=}';
    protected $description = 'Show recent Laravel logs';

    public function handle()
    {
        $lines = $this->option('lines');
        $grep = $this->option('grep');
        
        $logFile = storage_path('logs/laravel.log');
        
        if (!file_exists($logFile)) {
            $this->error("Log file not found: $logFile");
            return 1;
        }
        
        $content = file($logFile);
        $lastLines = array_slice($content, -$lines);
        
        foreach ($lastLines as $line) {
            if (empty($grep) || stripos($line, $grep) !== false) {
                echo $line;
            }
        }
        
        return 0;
    }
}
