<div>
    @if($isAdmin)
        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_280px]">
            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
                @if($trackedPerson->last_instagram_status_message)
                    <p class="rounded-3xl border border-slate-200 bg-white px-3 py-2 text-sm leading-6 text-slate-600">
                        {{ $trackedPerson->last_instagram_status_message }}
                    </p>
                @endif

                <div class="mt-4 flex flex-wrap gap-2 text-xs">
                    @if($trackedPerson->instagram_username)
                        <span class="rounded-2xl bg-pink-50 px-3 py-1 font-semibold text-pink-700 ring-1 ring-pink-100">Instagram</span>
                    @endif
                    @if($trackedPerson->monitoring_enabled)
                        <span class="rounded-2xl bg-slate-950 px-3 py-1 font-semibold text-white">Dauerbeobachtung aktiv</span>
                    @endif
                    @if($trackedPerson->notify_social_changes && $trackedPerson->notify_instagram_changes)
                        <span class="rounded-2xl bg-sky-50 px-3 py-1 font-semibold text-sky-700 ring-1 ring-sky-100">Benachrichtigungen aktiv</span>
                    @endif
                    @if($trackedPerson->last_instagram_analyzed_at)
                        <span class="rounded-2xl bg-slate-100 px-3 py-1 font-semibold text-slate-700">
                            {{ $trackedPerson->last_instagram_analyzed_at->copy()->timezone(config('app.timezone'))->diffForHumans() }}
                        </span>
                    @endif
                </div>
            </div>

            <div class="grid gap-2 sm:grid-cols-1">
                @if($trackedPerson->exists)
                    <button
                        type="button"
                        wire:click="$dispatch('tracked-person-run-instagram-analysis')"
                        @disabled(! $trackedPerson->instagram_username)
                        class="inline-flex h-11 items-center justify-center rounded-3xl bg-gradient-to-r from-rose-500 to-fuchsia-600 px-4 text-sm font-semibold text-white shadow-sm hover:from-rose-600 hover:to-fuchsia-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Vollanalyse
                    </button>
                @else
                    <div class="rounded-3xl border border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-500">
                        Alle uebrigen Aktionen sind jetzt im Menue oben rechts verfuegbar.
                    </div>
                @endif
            </div>
        </div>

        <x-instagram.detail-status-alert :message="$detailStatus" :level="$detailStatusLevel" />
    @endif
</div>
