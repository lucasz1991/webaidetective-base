<?php

namespace App\Livewire;

use Livewire\Component;

class UserNavigationMenu extends Component
{
    public $currentUrl;

    public $receivedMessages;

    public $unreadMessagesCount;

    public $previousUnreadMessagesCount = null;

    public $message;

    public ?array $selectedMessagePreview = null;

    public bool $showMessagePreviewModal = false;

    public ?int $selectedMessagePreviewId = null;

    public array $subscriptionSummary = [];

    protected $listeners = [
        'refreshComponent' => 'refreshNavigationData',
        'refresh-user-navigation-menu' => 'refreshNavigationData',
    ];

    public function mount(): void
    {
        $this->refreshNavigationData();
    }

    public function refreshNavigationData(): void
    {
        $this->currentUrl = url()->current();

        if (! auth()->check()) {
            $this->receivedMessages = collect();
            $this->unreadMessagesCount = 0;
            $this->previousUnreadMessagesCount = null;
            $this->subscriptionSummary = [];

            return;
        }

        $user = auth()->user()->loadMissing([
            'activeSubscription.plan',
            'creditWallet',
        ]);

        $this->receivedMessages = $user
            ->receivedMessages()
            ->orderBy('status')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        $newUnreadMessagesCount = $user
            ->receivedUnreadMessages()
            ->count();

        if ($this->previousUnreadMessagesCount !== null
            && $newUnreadMessagesCount > $this->previousUnreadMessagesCount) {
            $this->dispatch('playNotificationSound');
        }

        $this->unreadMessagesCount = $newUnreadMessagesCount;
        $this->previousUnreadMessagesCount = $newUnreadMessagesCount;
        $this->subscriptionSummary = $this->buildSubscriptionSummary($user);
    }

    public function setMessageStatus($messageId): void
    {
        $this->message = auth()->user()
            ->receivedMessages()
            ->find($messageId);

        if (! $this->message) {
            $this->refreshNavigationData();

            return;
        }

        $this->message->update([
            'status' => 2,
        ]);

        $this->refreshNavigationData();
    }

    public function showMessagePreview($messageId): void
    {
        if (! auth()->check()) {
            $this->selectedMessagePreview = null;
            $this->selectedMessagePreviewId = null;
            $this->showMessagePreviewModal = false;

            return;
        }

        $message = auth()->user()
            ->receivedMessages()
            ->find($messageId);

        if (! $message) {
            $this->selectedMessagePreview = null;
            $this->selectedMessagePreviewId = null;
            $this->showMessagePreviewModal = false;
            $this->refreshNavigationData();

            return;
        }

        $wasUnread = (int) $message->status === 1;

        $message->update([
            'status' => 2,
        ]);

        $this->receivedMessages
            ?->firstWhere('id', $message->id)
            ?->setAttribute('status', 2);

        if ($wasUnread) {
            $this->unreadMessagesCount = max(0, ((int) $this->unreadMessagesCount) - 1);
            $this->previousUnreadMessagesCount = $this->unreadMessagesCount;
        }

        $this->selectedMessagePreviewId = $message->id;
        $this->selectedMessagePreview = [
            'created_at' => $message->created_at?->diffForHumans() ?? '',
            'subject' => $message->subject ?? '',
            'body' => $message->message ?? '',
        ];
        $this->showMessagePreviewModal = true;
    }

    public function closeMessagePreview(): void
    {
        $this->selectedMessagePreview = null;
        $this->selectedMessagePreviewId = null;
        $this->showMessagePreviewModal = false;

        $this->refreshNavigationData();
    }

    public function updatedShowMessagePreviewModal($value): void
    {
        if (! $value) {
            $this->selectedMessagePreview = null;
            $this->selectedMessagePreviewId = null;
        }
    }

    public function render()
    {
        return view('livewire.user-navigation-menu');
    }

    private function buildSubscriptionSummary($user): array
    {
        $subscription = $user->activeSubscription;
        $plan = $subscription?->plan;
        $wallet = $user->creditWallet;
        $status = $subscription?->status;
        $monthlyCredits = (int) ($plan?->monthly_credits ?? 0);
        $usedCredits = (int) ($wallet?->used_credits ?? 0);
        $availableCredits = (int) ($wallet?->available_credits ?? 0);
        $reservedCredits = (int) ($wallet?->reserved_credits ?? 0);
        $bonusCredits = (int) ($wallet?->bonus_credits ?? 0);
        $scanUsagePercent = $monthlyCredits > 0
            ? min(100, (int) round(($usedCredits / $monthlyCredits) * 100))
            : 0;

        $statusLabel = match ($status) {
            'active' => 'Abo aktiv',
            'trialing' => 'Testphase',
            'paused' => 'Pausiert',
            'cancelled' => 'Gekuendigt',
            default => $subscription ? 'Abo inaktiv' : 'Kein Abo',
        };

        $statusClasses = match ($status) {
            'active' => 'bg-emerald-100 text-emerald-700 ring-emerald-200',
            'trialing' => 'bg-sky-100 text-sky-700 ring-sky-200',
            'paused' => 'bg-amber-100 text-amber-700 ring-amber-200',
            'cancelled' => 'bg-rose-100 text-rose-700 ring-rose-200',
            default => 'bg-slate-100 text-slate-600 ring-slate-200',
        };

        $iconClasses = match ($status) {
            'active' => 'text-emerald-600',
            'trialing' => 'text-sky-600',
            'paused' => 'text-amber-600',
            'cancelled' => 'text-rose-600',
            default => 'text-slate-400',
        };

        return [
            'has_subscription' => (bool) $subscription,
            'status' => $status,
            'status_label' => $statusLabel,
            'status_classes' => $statusClasses,
            'icon_classes' => $iconClasses,
            'plan_name' => $plan?->name ?: 'Free',
            'ends_at' => $subscription?->ends_at?->format('d.m.Y'),
            'monthly_credits' => $monthlyCredits,
            'available_credits' => $availableCredits,
            'reserved_credits' => $reservedCredits,
            'used_credits' => $usedCredits,
            'bonus_credits' => $bonusCredits,
            'scan_usage_percent' => $scanUsagePercent,
            'scan_usage_label' => $monthlyCredits > 0
                ? number_format($usedCredits, 0, ',', '.').' / '.number_format($monthlyCredits, 0, ',', '.').' Credits genutzt'
                : number_format($usedCredits, 0, ',', '.').' Credits genutzt',
        ];
    }
}
