<?php

namespace App\Livewire\Admin\Config;

use App\Models\Plan;
use App\Models\Setting;
use Livewire\Component;

class BillingSettings extends Component
{
    public array $plans = [];

    public array $creditCosts = [];

    public array $creditPackages = [];

    public function mount(): void
    {
        $this->loadSettings();
    }

    public function savePlans(): void
    {
        $validated = $this->validate([
            'plans' => ['required', 'array'],
            'plans.*.id' => ['required', 'integer', 'exists:plans,id'],
            'plans.*.name' => ['required', 'string', 'max:100', 'distinct:strict'],
            'plans.*.max_profiles' => ['required', 'integer', 'min:1', 'max:1000000'],
            'plans.*.max_users' => ['required', 'integer', 'min:1', 'max:1000000'],
            'plans.*.monthly_credits' => ['required', 'integer', 'min:0'],
            'plans.*.max_history_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'plans.*.scan_frequency_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'plans.*.priority_level' => ['required', 'integer', 'min:0', 'max:1000000'],
            'plans.*.features_text' => ['nullable', 'string', 'max:5000'],
        ]);

        foreach ($validated['plans'] as $planData) {
            $featuresText = (string) ($planData['features_text'] ?? '');

            Plan::query()
                ->whereKey($planData['id'])
                ->update([
                    'name' => $planData['name'],
                    'max_profiles' => $planData['max_profiles'],
                    'max_users' => $planData['max_users'],
                    'monthly_credits' => $planData['monthly_credits'],
                    'max_history_days' => $planData['max_history_days'],
                    'scan_frequency_minutes' => $planData['scan_frequency_minutes'],
                    'priority_level' => $planData['priority_level'],
                    'features' => $this->featuresFromText($featuresText),
                ]);
        }

        $this->loadSettings();
        session()->flash('success', 'Pakete wurden gespeichert.');
        $this->dispatch('showAlert', 'Pakete wurden gespeichert.', 'success');
    }

    public function saveCreditSettings(): void
    {
        $validated = $this->validate([
            'creditCosts.profile_scan' => ['required', 'integer', 'min:0', 'max:1000000'],
            'creditCosts.profile_image_scan' => ['required', 'integer', 'min:0', 'max:1000000'],
            'creditCosts.post_scan' => ['required', 'integer', 'min:0', 'max:1000000'],
            'creditCosts.new_posts_archive' => ['required', 'integer', 'min:0', 'max:1000000'],
            'creditCosts.media_download_per_file' => ['required', 'integer', 'min:0', 'max:1000000'],
            'creditCosts.ai_analysis_multiplier' => ['required', 'integer', 'min:1', 'max:1000000'],
            'creditPackages' => ['required', 'array', 'size:4'],
            'creditPackages.*.name' => ['required', 'string', 'max:100'],
            'creditPackages.*.credits' => ['required', 'integer', 'min:1'],
        ]);

        $this->setBillingSetting('credit_costs', $validated['creditCosts']);
        $this->setBillingSetting('credit_packages', array_values($validated['creditPackages']));

        $this->loadSettings();
        session()->flash('success', 'Credit-Konfiguration wurde gespeichert.');
        $this->dispatch('showAlert', 'Credit-Konfiguration wurde gespeichert.', 'success');
    }

    public function render()
    {
        return view('livewire.admin.config.billing-settings');
    }

    private function loadSettings(): void
    {
        $this->plans = Plan::query()
            ->orderBy('priority_level')
            ->orderBy('id')
            ->get()
            ->map(fn (Plan $plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
                'max_profiles' => $plan->max_profiles,
                'max_users' => $plan->max_users,
                'monthly_credits' => $plan->monthly_credits,
                'max_history_days' => $plan->max_history_days,
                'scan_frequency_minutes' => $plan->scan_frequency_minutes,
                'priority_level' => $plan->priority_level,
                'features_text' => implode("\n", $plan->features ?? []),
            ])
            ->all();

        $storedCreditCosts = Setting::getValue('billing', 'credit_costs');

        $this->creditCosts = [
            ...$this->defaultCreditCosts(),
            ...(is_array($storedCreditCosts) ? $storedCreditCosts : []),
        ];

        $packages = Setting::getValue('billing', 'credit_packages');
        $this->creditPackages = is_array($packages) && count($packages) === 4
            ? array_values($packages)
            : $this->defaultCreditPackages();
    }

    private function setBillingSetting(string $key, array $value): void
    {
        Setting::query()->updateOrCreate(
            ['type' => 'billing', 'key' => $key],
            ['value' => $value],
        );
    }

    private function featuresFromText(string $featuresText): array
    {
        return collect(preg_split('/\r\n|\r|\n/', $featuresText) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    private function defaultCreditCosts(): array
    {
        return [
            'profile_scan' => 1,
            'profile_image_scan' => 1,
            'post_scan' => 3,
            'new_posts_archive' => 5,
            'media_download_per_file' => 5,
            'ai_analysis_multiplier' => 1000,
        ];
    }

    private function defaultCreditPackages(): array
    {
        return [
            ['name' => 'Small', 'credits' => 10000],
            ['name' => 'Medium', 'credits' => 50000],
            ['name' => 'Large', 'credits' => 250000],
            ['name' => 'Ultra', 'credits' => 1000000],
        ];
    }
}
