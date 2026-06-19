@props([
    'scanCostSummary' => [],
    'disabled' => false,
    'profileUrl' => null,
    'showListLinks' => true,
    'listActionMode' => 'method',
])

@php
    $profileCost = (int) data_get($scanCostSummary, 'profile', 0);
    $postCost = (int) data_get($scanCostSummary, 'post', $profileCost);
@endphp

<div class="px-3 pb-1 pt-2 text-[11px] font-bold uppercase tracking-wide text-slate-400">Profil scannen</div>

<button
    type="button"
    @click="$dispatch('close')"
    wire:click="analyzeInstagramMini"
    wire:loading.attr="disabled"
    wire:target="analyzeInstagramMini"
    @disabled($disabled)
    class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
>
    Mini-Scan
    <span class="mt-0.5 block text-xs font-normal text-slate-500">
        Profilwerte aktualisieren &middot; ab {{ number_format($profileCost, 0, ',', '.') }} Credits
    </span>
</button>

<button
    type="button"
    @click="$dispatch('close')"
    wire:click="analyzeInstagram"
    wire:loading.attr="disabled"
    wire:target="analyzeInstagram"
    @disabled($disabled)
    class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-pink-700 hover:bg-pink-50 disabled:cursor-not-allowed disabled:opacity-50"
>
    Vollanalyse
    <span class="mt-0.5 block text-xs font-normal text-pink-500">
        Ab {{ number_format($profileCost, 0, ',', '.') }} Credits plus Folge-Scans
    </span>
</button>

<div class="my-2 border-t border-slate-100"></div>
<div class="px-3 pb-1 pt-2 text-[11px] font-bold uppercase tracking-wide text-slate-400">Listen & Inhalte</div>

<button
    type="button"
    @click="$dispatch('close')"
    wire:click="scanInstagramFollowersList"
    wire:loading.attr="disabled"
    wire:target="scanInstagramFollowersList"
    @disabled($disabled)
    class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-sky-700 hover:bg-sky-50 disabled:cursor-not-allowed disabled:opacity-50"
>
    Followerliste scannen
    <span class="mt-0.5 block text-xs font-normal text-sky-500">Ab {{ number_format($profileCost, 0, ',', '.') }} Credits</span>
</button>

<button
    type="button"
    @click="$dispatch('close')"
    wire:click="scanInstagramFollowingList"
    wire:loading.attr="disabled"
    wire:target="scanInstagramFollowingList"
    @disabled($disabled)
    class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-sky-700 hover:bg-sky-50 disabled:cursor-not-allowed disabled:opacity-50"
>
    Gefolgt-Liste scannen
    <span class="mt-0.5 block text-xs font-normal text-sky-500">Ab {{ number_format($profileCost, 0, ',', '.') }} Credits</span>
</button>

<button
    type="button"
    @click="$dispatch('close')"
    wire:click="scanInstagramPosts"
    wire:loading.attr="disabled"
    wire:target="scanInstagramPosts"
    @disabled($disabled)
    class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-violet-700 hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-50"
>
    Beitraege scannen
    <span class="mt-0.5 block text-xs font-normal text-violet-500">Ab {{ number_format($postCost, 0, ',', '.') }} Credits</span>
</button>

<button
    type="button"
    @click="$dispatch('close')"
    wire:click="scanInstagramSuggestions"
    wire:loading.attr="disabled"
    wire:target="scanInstagramSuggestions"
    @disabled($disabled)
    class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-fuchsia-700 hover:bg-fuchsia-50 disabled:cursor-not-allowed disabled:opacity-50"
>
    Vorschlaege-Scan
    <span class="mt-0.5 block text-xs font-normal text-fuchsia-500">
        Direkte Vorschlaege &middot; ab {{ number_format($profileCost, 0, ',', '.') }} Credits
    </span>
</button>

<button
    type="button"
    @click="$dispatch('close')"
    wire:click="scanInstagramSuggestionDeepSearch"
    wire:loading.attr="disabled"
    wire:target="scanInstagramSuggestionDeepSearch"
    @disabled($disabled)
    class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-fuchsia-700 hover:bg-fuchsia-50 disabled:cursor-not-allowed disabled:opacity-50"
>
    Vorschlaege DeepSearch
    <span class="mt-0.5 block text-xs font-normal text-fuchsia-500">
        Vorschlaege und Listen &middot; ab {{ number_format($profileCost, 0, ',', '.') }} Credits
    </span>
</button>

<div class="my-2 border-t border-slate-100"></div>
<div class="px-3 pb-1 pt-2 text-[11px] font-bold uppercase tracking-wide text-slate-400">Aktionen</div>

<button
    type="button"
    @click="$dispatch('close')"
    wire:click="$dispatch('open-instagram-scan-costs-modal')"
    class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
>
    Scan-Kosten
</button>

{{ $slot }}

@if($profileUrl)
    <a
        href="{{ $profileUrl }}"
        target="_blank"
        rel="noopener noreferrer"
        @click="$dispatch('close')"
        class="block rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
    >
        Instagram oeffnen
    </a>
@endif

@if($showListLinks)
    @if($listActionMode === 'dispatch')
        <button
            type="button"
            @click="$dispatch('close')"
            wire:click="$dispatch('open-tracked-person-relationship-list', { listType: 'followers' })"
            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
        >
            Follower-Liste ansehen
        </button>

        <button
            type="button"
            @click="$dispatch('close')"
            wire:click="$dispatch('open-tracked-person-relationship-list', { listType: 'following' })"
            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
        >
            Gefolgt-Liste ansehen
        </button>
    @else
        <button
            type="button"
            @click="$dispatch('close')"
            wire:click="openListModal('followers')"
            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
        >
            Follower-Liste ansehen
        </button>

        <button
            type="button"
            @click="$dispatch('close')"
            wire:click="openListModal('following')"
            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
        >
            Gefolgt-Liste ansehen
        </button>
    @endif
@endif
