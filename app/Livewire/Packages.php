<?php

namespace App\Livewire;

use App\Models\Plan;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class Packages extends Component
{
    public function render()
    {
        $plans = $this->publicPlans();

        return view('livewire.packages', compact('plans'))->layout('layouts.app');
    }

    private function publicPlans(): array
    {
        $priceByName = [
            'Basic' => 49,
            'Pro' => 149,
            'Platin' => 399,
        ];
        $descriptionByName = [
            'Basic' => 'Fuer Einzelpersonen und fokussierte Recherchen mit regelmaessigem Profil-Monitoring.',
            'Pro' => 'Fuer aktive Ermittlungen mit groesseren Netzwerken, kuerzeren Intervallen und AI-gestuetzten Workflows.',
            'Platin' => 'Fuer Teams und Organisationen mit hohem Scanvolumen und umfangreicher Historie.',
        ];

        $plans = Schema::hasTable('plans')
            ? Plan::query()->orderBy('priority_level')->get()
            : collect($this->fallbackPlans());

        return $plans
            ->map(function ($plan) use ($priceByName, $descriptionByName): array {
                $name = (string) data_get($plan, 'name', 'Plan');

                return [
                    'name' => $name,
                    'price' => $priceByName[$name] ?? null,
                    'description' => $descriptionByName[$name] ?? 'Flexibler Social-Intelligence-Workspace fuer dein Recherchevolumen.',
                    'max_profiles' => (int) data_get($plan, 'max_profiles', 0),
                    'max_users' => (int) data_get($plan, 'max_users', 1),
                    'monthly_credits' => (int) data_get($plan, 'monthly_credits', 0),
                    'max_history_days' => (int) data_get($plan, 'max_history_days', 0),
                    'scan_frequency_minutes' => (int) data_get($plan, 'scan_frequency_minutes', 60),
                    'features' => array_values(array_filter((array) data_get($plan, 'features', []))),
                    'featured' => $name === 'Pro',
                ];
            })
            ->values()
            ->all();
    }

    private function fallbackPlans(): array
    {
        return [
            [
                'name' => 'Basic',
                'max_profiles' => 10,
                'max_users' => 1,
                'monthly_credits' => 5000,
                'max_history_days' => 30,
                'scan_frequency_minutes' => 60,
                'features' => ['Basis-Benachrichtigungen', 'Profil-, Bio- und Beitragsaenderungen'],
            ],
            [
                'name' => 'Pro',
                'max_profiles' => 100,
                'max_users' => 3,
                'monthly_credits' => 50000,
                'max_history_days' => 180,
                'scan_frequency_minutes' => 15,
                'features' => ['Erweiterte Analysen', 'Netzwerkanalysen', 'Exportfunktionen'],
            ],
            [
                'name' => 'Platin',
                'max_profiles' => 1000,
                'max_users' => 10,
                'monthly_credits' => 500000,
                'max_history_days' => 365,
                'scan_frequency_minutes' => 5,
                'features' => ['Alle Analysefunktionen', 'Teamverwaltung', 'Priorisierte Worker'],
            ],
        ];
    }
}
