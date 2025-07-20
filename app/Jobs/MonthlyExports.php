<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Carbon;
use App\Models\Customer;
use App\Models\Setting;

class MonthlyExports implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $year = Carbon::now()->subMonth()->format('Y');
        $month = Carbon::now()->subMonth()->format('m');

        // **Einstellungen abrufen**
        $autoExport = Setting::where('type', 'exports')->where('key', 'auto_export')->pluck('value')->first() ?? false;
        $exportEmail = Setting::where('type', 'exports')->where('key', 'export_email')->pluck('value')->first() ?? null;

        if (!$autoExport || !$exportEmail) {
            \Log::info("📌 Automatische Exporte deaktiviert oder keine E-Mail-Adresse hinterlegt.");
            return;
        }

        // **Dateien generieren**
        $files = [
            'Kunden' => $this->exportCustomers($year, $month),
        ];

        // **Nicht vorhandene Dateien entfernen**
        $files = array_filter($files);

        if (empty($files)) {
            \Log::info("❌ Keine Daten für den Export gefunden.");
            return;
        }
  

        // **E-Mail versenden**
        Mail::raw("Die monatlichen Exporte sind im Anhang.", function ($message) use ($exportEmail2, $files) {
            $message->to($exportEmail2)
                    ->subject('📊 Monatliche Exporte');

            foreach ($files as $file) {
                $message->attach($file);
            }
        });

        // **E-Mail versenden**
        Mail::raw("Die monatlichen Exporte sind im Anhang.", function ($message) use ($exportEmail, $files) {
            $message->to($exportEmail)
                    ->subject('📊 Monatliche Exporte');

            foreach ($files as $file) {
                $message->attach($file);
            }
        });

        \Log::info("📧 Export-E-Mail erfolgreich an $exportEmail gesendet.");
    }



    private function exportCustomers($year, $month)
    {
        $customers = Customer::whereYear('created_at', $year)
                            ->whereMonth('created_at', $month)
                            ->get();

        if ($customers->isEmpty()) {
            return null;
        }

        $csv = $this->generateCsv($customers, [
            'Kunden-ID', 'Name', 'E-Mail', 'Telefon', 'Straße', 'Stadt', 'Bundesland', 'PLZ', 'Land', 'Registrierungsdatum'
        ], function ($customer) {
            return [
                $this->sanitizeString(optional($customer->user)->id), 
                $this->sanitizeString($customer->first_name . ' ' . $customer->last_name),
                optional($customer->user)->email, 
                $this->sanitizeString($customer->phone_number),
                $this->sanitizeString($customer->street),
                $this->sanitizeString($customer->city),
                $this->sanitizeString($customer->state),
                $this->sanitizeString($customer->postal_code),
                $this->sanitizeString($customer->country),
                $this->sanitizeString(Carbon::parse($customer->created_at)->format('d.m.Y H:i')),
            ];
        });

        return $this->saveCsv($csv, "kunden_export_{$year}-{$month}.csv");
    }


    private function generateCsv($data, $headers, $callback)
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers, ';');

        foreach ($data as $row) {
            fputcsv($handle, $callback($row), ';');
        }

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return $csvContent;
    }

    private function saveCsv($csv, $filename)
    {
        Storage::disk('local')->put("exports/$filename", $csv);
        return storage_path("app/exports/$filename");
    }

    /**
     * Entfernt unerwünschte Zeichen aus einem String.
     */
    private function sanitizeString($string)
    {
        if (!is_string($string)) {
            return $string;
        }
    
        // Zuerst Umlaute explizit ersetzen
        $umlautMapping = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
        ];
        $string = strtr($string, $umlautMapping);
    
        // Alle übrigen diakritischen Zeichen (z.B. é, è, ê, etc.) transliterieren
        $string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string);
    
        // Entfernt nicht-druckbare Zeichen & Steuerzeichen
        $string = preg_replace('/[\x00-\x1F\x7F]/', '', $string);
    
        // Erlaubt Buchstaben, Zahlen, Satzzeichen & Leerzeichen
        $string = preg_replace('/[^A-Za-z0-9\s.,;:!?\-()€$]/', '', $string);
    
        return trim($string);
    }
}
