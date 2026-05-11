<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $user = Auth::user();
        $trackedPeopleQuery = $user?->trackedPeople();
        $stats = [
            'total' => 0,
            'with_instagram' => 0,
            'monitored' => 0,
            'notifications_enabled' => 0,
            'analyzed' => 0,
        ];

        if ($trackedPeopleQuery) {
            $stats = [
                'total' => (clone $trackedPeopleQuery)->count(),
                'with_instagram' => (clone $trackedPeopleQuery)->whereNotNull('instagram_username')->count(),
                'monitored' => (clone $trackedPeopleQuery)->where('monitoring_enabled', true)->count(),
                'notifications_enabled' => (clone $trackedPeopleQuery)->where('notify_social_changes', true)->count(),
                'analyzed' => (clone $trackedPeopleQuery)->whereNotNull('last_instagram_analyzed_at')->count(),
            ];
        }

        return view('livewire.dashboard', [
            'userData' => $user,
            'stats' => $stats,
        ])->layout('layouts.app');
    }
}
