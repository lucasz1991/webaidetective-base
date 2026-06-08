<?php

namespace App\Livewire\Pages;

use Livewire\Component;

class Sitemap extends Component
{
    public function render()
    {
        $pages = [
            ['title' => 'Start', 'url' => route('welcome')],
            ['title' => 'Pakete', 'url' => route('packages')],
            ['title' => 'How To', 'url' => url('/howto')],
            ['title' => 'FAQs', 'url' => url('/faqs')],
            ['title' => 'Terms and Conditions', 'url' => url('/termsandconditions')],
            ['title' => 'Imprint', 'url' => url('/imprint')],
            ['title' => 'Privacy Policy', 'url' => url('/privacypolicy')],
            ['title' => 'Contact', 'url' => url('/contact')],
        ];

        return view('livewire.pages.sitemap', compact('pages'))->layout('layouts.app');
    }
}
