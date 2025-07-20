<?php

namespace App\Charts;

use ArielMejiaDev\LarapexCharts\LarapexChart;

class ActiveUsersChart
{
    protected $chart;

    public function __construct(LarapexChart $chart)
    {
        $this->chart = $chart;
    }

    public function build(array $data, array $timestamps): LarapexChart
    {
        return $this->chart->barChart()
            ->setTitle('Aktive Nutzer')
            ->setSubtitle('Live-Aktualisierung der letzten 10 Intervalle')
            ->addData('Nutzer', $data) // Y-Werte: Anzahl aktiver Nutzer
            ->setXAxis($timestamps) // X-Achse: Zeitintervalle
            ->setGrid(true)
            ->setHorizontal(true); // Horizontal ausgerichtete Balken
    }
}


