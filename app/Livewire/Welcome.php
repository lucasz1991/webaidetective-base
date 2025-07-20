<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;

class Welcome extends Component
{
    public $teilnehmerDaten;

    public function mount()
    {
        $this->teilnehmerDaten = [
            "teilnehmer" => [
                "teilnehmerNr" => "000007",
                "name" => "Herr Müstermann, Mäx",
                "geburtsdatum" => "1982-03-28",
                "kundenNr" => "1006280",
                "stammklasse" => "XXX1",
                "eignungstest" => "56%"
            ],
            "massnahme" => [
                "titel" => "V AAAA",
                "zeitraum" => [
                    "von" => "2019-09-02",
                    "bis" => "2020-02-28"
                ],
                "bausteine" => 12,
                "inhalte" => "SAP ERP FI / CO & ECDL"
            ],
            "vertrag" => [
                "vertrag" => "V",
                "kennung" => "AAAA",
                "von" => "2019-09-02",
                "bis" => "2020-02-28",
                "rechnungsnummer" => "0106687",
                "abschlussdatum" => "2019-08-05",
                "kündigung" => null,
                "storno" => null
            ],
            "traeger" => [
                "institution" => "Jobcenter Steglitz-Zehlendorf",
                "ansprechpartner" => "Herr Greinke",
                "adresse" => "Birkbuschstraße 10, 12167 Berlin"
            ],
            "bausteine" => [
                [
                    "block" => 1,
                    "abschnitt" => 1,
                    "beginn" => "2019-09-02",
                    "ende" => "2019-09-13",
                    "tage" => 10,
                    "unterrichtsklasse" => "OF99",
                    "baustein" => "FSIT Computergrundlagen - Windows 10",
                    "schnitt" => 93,
                    "punkte" => 91,
                    "fehltage" => 0
                ],
                [
                    "block" => 1,
                    "abschnitt" => 2,
                    "beginn" => "2019-09-16",
                    "ende" => "2019-09-27",
                    "tage" => 10,
                    "unterrichtsklasse" => "OF99",
                    "baustein" => "FSOS Online-Grundlagen - Edge und Outlook / IT-Sicherheit",
                    "schnitt" => 83,
                    "punkte" => 83,
                    "fehltage" => 0
                ],
                [
                    "block" => 1,
                    "abschnitt" => 3,
                    "beginn" => "2019-09-30",
                    "ende" => "2019-10-11",
                    "tage" => 8,
                    "unterrichtsklasse" => "OF99",
                    "baustein" => "FSTE Textverarbeitung mit Word",
                    "schnitt" => 81,
                    "punkte" => 97,
                    "fehltage" => 0
                ],
                [
                    "block" => 1,
                    "abschnitt" => 4,
                    "beginn" => "2019-10-14",
                    "ende" => "2019-10-25",
                    "tage" => 10,
                    "unterrichtsklasse" => "OF99",
                    "baustein" => "FSTA Tabellenkalkulation - Excel",
                    "schnitt" => null,
                    "punkte" => null,
                    "fehltage" => 0
                ],
                [
                    "block" => 1,
                    "abschnitt" => 5,
                    "beginn" => "2019-10-28",
                    "ende" => "2019-11-08",
                    "tage" => 10,
                    "unterrichtsklasse" => "OF99",
                    "baustein" => "FSPP PowerPoint",
                    "schnitt" => null,
                    "punkte" => null,
                    "fehltage" => 0
                ],
                [
                    "block" => 1,
                    "abschnitt" => 6,
                    "beginn" => "2019-11-11",
                    "ende" => "2019-11-22",
                    "tage" => 10,
                    "unterrichtsklasse" => null,
                    "baustein" => "FSDA Datenbanken - Access",
                    "schnitt" => null,
                    "punkte" => null,
                    "fehltage" => 0
                ],
                [
                    "block" => 2,
                    "abschnitt" => 1,
                    "beginn" => "2019-11-25",
                    "ende" => "2019-12-06",
                    "tage" => 10,
                    "unterrichtsklasse" => null,
                    "baustein" => "8ESA SAP01 SAP ERP Überblick",
                    "schnitt" => null,
                    "punkte" => null,
                    "fehltage" => 0
                ],
                [
                    "block" => 2,
                    "abschnitt" => 2,
                    "beginn" => "2019-12-09",
                    "ende" => "2019-12-20",
                    "tage" => 10,
                    "unterrichtsklasse" => null,
                    "baustein" => "8AAC AC010 Kreditoren-/ Debitoren-/ Hauptbuchhaltung",
                    "schnitt" => null,
                    "punkte" => null,
                    "fehltage" => 0
                ],
                [
                    "block" => 3,
                    "abschnitt" => 1,
                    "beginn" => "2020-01-06",
                    "ende" => "2020-01-17",
                    "tage" => 10,
                    "unterrichtsklasse" => null,
                    "baustein" => "8AST AC201/AC202/AC205 Zahlen & Mahnen/ Sonderhauptb./ Einzelabschl.",
                    "schnitt" => null,
                    "punkte" => null,
                    "fehltage" => 0
                ],
                [
                    "block" => 3,
                    "abschnitt" => 2,
                    "beginn" => "2020-01-20",
                    "ende" => "2020-01-31",
                    "tage" => 10,
                    "unterrichtsklasse" => null,
                    "baustein" => "8AAB AC305 Anlagenbuchhaltung",
                    "schnitt" => null,
                    "punkte" => null,
                    "fehltage" => 0
                ],
                [
                    "block" => 3,
                    "abschnitt" => 3,
                    "beginn" => "2020-02-03",
                    "ende" => "2020-02-14",
                    "tage" => 10,
                    "unterrichtsklasse" => null,
                    "baustein" => "8BZH SAP-Anwenderzertifizierung",
                    "schnitt" => null,
                    "punkte" => null,
                    "fehltage" => 0
                ],
                [
                    "block" => 3,
                    "abschnitt" => 4,
                    "beginn" => "2020-02-17",
                    "ende" => "2020-02-28",
                    "tage" => 10,
                    "unterrichtsklasse" => null,
                    "baustein" => "8AC1 AC040 Geschäftsprozesse im Controlling",
                    "schnitt" => null,
                    "punkte" => null,
                    "fehltage" => 0
                ]
            ],

            "unterricht" => [
                "tage" => 118,
                "einheiten" => 960,
                "note" => "mit sehr gutem Erfolg",
                "schnitt" => 85,
                "punkte" => 91,
                "fehltage" => 0
            ],
            "praktikum" => [
                "tage" => 0,
                "stunden" => 0,
                "bemerkung" => null
            ]
        ];
    }

    public function render()
    {
        return view('livewire.welcome')->layout('layouts.app');
    }
}
