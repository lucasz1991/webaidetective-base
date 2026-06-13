<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminConfigAudioModelsTest extends TestCase
{
    public function test_admin_config_contains_audio_model_settings(): void
    {
        $component = file_get_contents(app_path('Livewire/AdminConfig.php'));
        $view = file_get_contents(resource_path('views/livewire/admin-config.blade.php'));

        $this->assertStringContainsString("'audio_input_model'", $component);
        $this->assertStringContainsString("'audio_output_model'", $component);
        $this->assertStringContainsString('wire:submit="saveAudioModels"', $view);
        $this->assertStringContainsString('AI-Audio', $view);
    }
}
