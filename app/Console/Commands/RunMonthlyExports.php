<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\MonthlyExports;

class RunMonthlyExports extends Command
{
    /**
     * Der Konsolenbefehl.
     *
     * @var string
     */
    protected $signature = 'exports:run';

    /**
     * Beschreibung des Befehls.
     *
     * @var string
     */
    protected $description = 'Führt den monatlichen Export von Buchungen, Verlängerungen, Auszahlungen und Kunden aus.';

    /**
     * Ausführung des Commands.
     */
    public function handle()
    {
        $this->info('📦 Starte den monatlichen Export...');

        // Den Job dispatchen
        dispatch(new MonthlyExports());

        $this->info('✅ Export-Job wurde erfolgreich in die Queue geschickt.');
    }
}

