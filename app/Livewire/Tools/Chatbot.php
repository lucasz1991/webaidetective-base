<?php

namespace App\Livewire\Tools;

use Livewire\Component;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use App\Models\Setting;


class Chatbot extends Component
{
    public $message = '';
    public $chatHistory;
    public $isLoading = false;

    public $status, $assistantName, $apiUrl, $apiKey, $aiModel, $modelTitle, $refererUrl, $trainContent;

    protected $listeners = ['sendMessage' => 'sendMessage'];

    public function mount()
    {
        // Lade die Chat-Historie aus der Session, falls vorhanden
        $this->chatHistory = Session::get('chatbot_history', []);
        $this->status = Setting::getValue('ai_assistant', 'status');
        $this->assistantName = Setting::getValue('ai_assistant', 'assistant_name');
        $this->apiUrl = Setting::getValue('ai_assistant', 'api_url');
        $this->apiKey = Setting::getValue('ai_assistant', 'api_key');
        $this->aiModel = Setting::getValue('ai_assistant', 'ai_model');
        $this->modelTitle = Setting::getValue('ai_assistant', 'model_title');
        $this->refererUrl = Setting::getValue('ai_assistant', 'referer_url');
        $this->trainContent = Setting::getValue('ai_assistant', 'train_content');
    }

    public function sendMessage()
    {
        if (trim($this->message) === '') {
            return;
        }

        // Benutzerfrage zur Historie hinzufügen
        //$this->chatHistory[] = ['role' => 'user', 'content' => $this->message];
        Log::info("Chatbot: Neue Nachricht gesendet → {$this->message}");

        $this->isLoading = true;
        $userMessage = $this->message;
        $this->message = '';

        // API-Call vorbereiten
        $maxRetries = 5;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey, 
                    'HTTP-Referer' => $this->refererUrl, 
                    'X-Title' => $this->modelTitle, 
                    'Content-Type'  => 'application/json',
                ])->post($this->apiUrl, [
                    'model'    => $this->aiModel,
                    'messages' => array_merge([
                        [
                            'role'    => 'system',
                            'content' => trim(preg_replace('/\s+/', ' ', $this->trainContent))
                        ]
                    ], $this->chatHistory)
                ]);

                $botMessage = $response->json()['choices'][0]['message']['content'] ?? '';

                if (!empty($botMessage)) {
                    // Bot message auf nicht deutsche zeichen filtern und entfernen 
                    $botMessage = preg_replace('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}]/u', '', $botMessage);

                    $this->chatHistory[] = ['role' => 'assistant', 'content' => $botMessage];
                    Session::put('chatbot_history', $this->chatHistory);

                    $this->isLoading = false;
                    return;
                }

            } catch (\Exception $e) {
            }
        }

        // Falls nach 5 Versuchen keine Antwort kommt
        $this->chatHistory[] = ['role' => 'assistant', 'content' => "Ich habe dazu leider keine Antwort."];
        Session::put('chatbot_history', $this->chatHistory);
        $this->isLoading = false;
    }

    public function clearChat()
    {
        Session::forget('chatbot_history');
        $this->chatHistory = [];
    }

    public function render()
    {
        return view('livewire.tools.chatbot');
    }
}
