<?php

namespace App\Http\Controllers\Proxy;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\Controller;

class TorProxyController extends Controller
{

    public function fetchDirect(string $url): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return 'Ungültige URL';
        }

        try {
            $response = Http::withOptions([
                'proxy' => 'socks5://shopspaze.com:32769',
                'verify' => false,
                'timeout' => 15,
            ])->get($url);

            return $response->body();
        } catch (\Exception $e) {
            return 'Fehler: ' . $e->getMessage();
        }
    }

}