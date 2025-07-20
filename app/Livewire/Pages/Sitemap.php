<?php

namespace App\Livewire\Pages;

use Livewire\Component;
use App\Models\Page; // Angenommen, du hast ein Page-Modell, um Seiten in der Datenbank zu verwalten.

class Sitemap extends Component
{
    public function render()
    {
        $pages = [
            ['title' => 'Home', 'url' => route('home')],
            ['title' => 'Booking', 'url' => route('booking')],
            ['title' => 'Products', 'url' => route('products')],
            ['title' => 'Product Detail', 'url' => route('product.show', ['id' => 1])], // Beispiel für einen Parameter
            ['title' => 'Shelf Rental', 'url' => route('shelf.show', ['shelfRentalId' => 1])], // Beispiel für einen Parameter
            ['title' => 'Prices', 'url' => route('prices')],
            ['title' => 'How To', 'url' => route('howto')],
            ['title' => 'About Us', 'url' => route('aboutus')],
            ['title' => 'FAQs', 'url' => route('faqs')],
            //['title' => 'Jobs', 'url' => route('jobs')],
            ['title' => 'Terms and Conditions', 'url' => route('terms')],
            ['title' => 'Imprint', 'url' => route('imprint')],
            ['title' => 'Privacy Policy', 'url' => route('privacypolicy')],
            ['title' => 'Contact', 'url' => route('contact')],
        ];

        return view('livewire.pages.sitemap', compact('pages'))->layout('layouts.app');
    }
}

