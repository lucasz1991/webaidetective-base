<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\Log;

class BuildFrontend extends Command
{
    protected $signature = 'frontend:build';
    protected $description = 'Führt npm run build aus, um das Frontend zu erstellen';

    public function handle()
    {
        $this->info('Frontend-Build wird gestartet...');
        Log::info('Frontend-Build wird gestartet...');

        // Plattformabhängig den npm-Pfad setzen
        $npmCommand = PHP_OS_FAMILY === 'Windows'
            ? 'C:\Program Files\nodejs\npx.cmd' // Windows nutzt `npx.cmd`
            : '/usr/bin/npx'; // Linux/macOS nutzen `npx`

        // Führe den npm build Befehl aus
        $process = new Process([$npmCommand, 'vite', 'build'], base_path(), null, null, 600);
        $process->setTimeout(600);

        try {
            $process->mustRun();
            $this->info("Frontend-Build erfolgreich abgeschlossen!");
            Log::info("Frontend-Build erfolgreich abgeschlossen!");
        } catch (ProcessFailedException $exception) {
            $this->error("Fehler beim Build: " . $exception->getMessage());
            Log::error("Fehler beim Build: " . $exception->getMessage());
        }

        return Command::SUCCESS;
    }
}
