<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\WebContent;

class FaqSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
                // Beispielinhalte für verschiedene Seiten
                $contents = [
                    [
                        'key' => 'Was ist das CBW Weiterbildungs Schulnetz?',
                        'value' => 'Das CBW Weiterbildungs Schulnetz ist eine Plattform, die Schulen, Lehrkräfte und Bildungsträger miteinander vernetzt, um Weiterbildung und Austausch zu fördern.',
                        'type' => 'faq'
                    ],
                    [
                        'key' => 'Wer kann das Schulnetz nutzen?',
                        'value' => 'Lehrkräfte, Schulleitungen, Bildungsträger und andere Akteure im Bildungsbereich können das Schulnetz nutzen.',
                        'type' => 'faq'
                    ],
                    [
                        'key' => 'Wie kann ich mich registrieren?',
                        'value' => 'Sie können sich über das Registrierungsformular auf unserer Website mit Ihrer dienstlichen E-Mail-Adresse anmelden.',
                        'type' => 'faq'
                    ],
                    [
                        'key' => 'Welche Weiterbildungsangebote finde ich im Schulnetz?',
                        'value' => 'Im Schulnetz finden Sie eine Vielzahl an Fortbildungen, Workshops und digitalen Lernangeboten für verschiedene Fachrichtungen.',
                        'type' => 'faq'
                    ],
                    [
                        'key' => 'Kostet die Nutzung des Schulnetzes etwas?',
                        'value' => 'Die Nutzung des CBW Weiterbildungs Schulnetzes ist für registrierte Nutzerinnen und Nutzer kostenlos.',
                        'type' => 'faq'
                    ],
                    [
                        'key' => 'Wie kann ich eigene Veranstaltungen einstellen?',
                        'value' => 'Nach der Anmeldung können Sie im Bereich „Veranstaltungen“ eigene Angebote einstellen und verwalten.',
                        'type' => 'faq'
                    ],
                    [
                        'key' => 'Wie finde ich passende Angebote für mein Fach?',
                        'value' => 'Sie können gezielt nach Fachrichtungen, Themen oder Schlagwörtern suchen und Filterfunktionen nutzen.',
                        'type' => 'faq'
                    ],
                    [
                        'key' => 'Kann ich mich zu Veranstaltungen direkt anmelden?',
                        'value' => 'Ja, Sie können sich direkt über das Schulnetz zu Veranstaltungen anmelden und erhalten eine Bestätigung per E-Mail.',
                        'type' => 'faq'
                    ],
                    [
                        'key' => 'Wie kann ich Feedback zu Veranstaltungen geben?',
                        'value' => 'Nach der Teilnahme erhalten Sie eine Einladung zur Feedbackabgabe, die Sie direkt im Schulnetz ausfüllen können.',
                        'type' => 'faq'
                    ],
                    [
                        'key' => 'An wen kann ich mich bei technischen Problemen wenden?',
                        'value' => 'Bei technischen Problemen steht Ihnen unser Support-Team per E-Mail oder Kontaktformular zur Verfügung.',
                        'type' => 'faq'
                    ],
                ];
                   
        
                foreach ($contents as $content) {
                    WebContent::updateOrCreate(['key' => $content['key']], $content);
                }
    }
}
