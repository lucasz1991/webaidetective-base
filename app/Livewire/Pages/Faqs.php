<?php

namespace App\Livewire\Pages;


use Livewire\Component;
use App\Models\WebContent;

class Faqs extends Component
{
    public $search = ''; // für die Suchfunktion



    public function render()
    {
        $faqs = WebContent::query()
        ->where('type', 'faq') // Nur FAQ-Inhalte abfragen
        ->when($this->search, function ($query) {
            // Suche sowohl im key (Frage) als auch im value (Antwort)
            $query->where(function ($query) {
                $query->where('key', 'like', '%' . $this->search . '%')
                      ->orWhere('value', 'like', '%' . $this->search . '%');
            });
        })
        ->get();

        return view('livewire.pages.faqs', ['faqs' => $faqs])->layout('layouts.app');
    }
}