<?php

namespace App\Livewire;

use App\Models\Setting;
use Livewire\Component;

class AdminConfig extends Component
{
    public string $activeTab = 'billing';

    public string $audioInputModel = '';

    public string $audioOutputModel = '';

    public function mount(): void
    {
        $this->audioInputModel = (string) (Setting::getValue('ai_assistant', 'audio_input_model') ?? '');
        $this->audioOutputModel = (string) (Setting::getValue('ai_assistant', 'audio_output_model') ?? '');
    }

    public function saveAudioModels(): void
    {
        $validated = $this->validate([
            'audioInputModel' => ['nullable', 'string', 'max:255'],
            'audioOutputModel' => ['nullable', 'string', 'max:255'],
        ]);

        Setting::query()->updateOrCreate(
            ['type' => 'ai_assistant', 'key' => 'audio_input_model'],
            ['value' => trim($validated['audioInputModel'])],
        );
        Setting::query()->updateOrCreate(
            ['type' => 'ai_assistant', 'key' => 'audio_output_model'],
            ['value' => trim($validated['audioOutputModel'])],
        );

        session()->flash('audio-models-saved', 'Audio-Modelle wurden gespeichert.');
    }

    public function render()
    {
        return view('livewire.admin-config')->layout('layouts.app');
    }
}
