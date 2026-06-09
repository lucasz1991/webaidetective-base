<div
    x-data="{
        showChat: $persist(false).using(sessionStorage),
        draft: @entangle('message'),
        isLoading: @entangle('isLoading'),
        chatHistory: @entangle('chatHistory'),
        toolEvents: @entangle('toolEvents'),
        send() {
            if (!this.draft || this.draft.trim() === '' || this.isLoading) return;
            $wire.set('message', this.draft);
            $wire.sendMessage();
        },
        quick(prompt) {
            if (this.isLoading) return;
            this.draft = prompt;
            $wire.set('message', prompt);
            $wire.sendMessage();
        }
    }"
    class="investigation-copilot"
>
    @if($status)
        <button
            x-show="!showChat"
            x-cloak
            x-on:click="showChat = true"
            class="fixed bottom-5 right-5 z-50 flex h-14 w-14 items-center justify-center bg-slate-950 text-white shadow-2xl shadow-slate-900/30 transition hover:bg-emerald-700"
            aria-label="Investigation Copilot öffnen"
        >
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 3 4 7v6c0 4.5 3.4 7.4 8 8 4.6-.6 8-3.5 8-8V7l-8-4Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                <path d="M9 12h6M12 9v6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </button>

        <div
            x-show="showChat"
            x-cloak
            x-transition.opacity
            class="fixed inset-0 z-40 bg-slate-950/35"
        ></div>

        <aside
            x-show="showChat"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="fixed bottom-0 right-0 top-0 z-50 flex w-[620px] max-w-full flex-col border-l border-slate-200 bg-white shadow-2xl"
        >
            <header class="border-b border-slate-200 bg-slate-950 px-5 py-4 text-white">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[.18em] text-emerald-300">AI Tool</p>
                        <h2 class="mt-1 text-xl font-black">{{ $assistantName ?: 'Investigation Copilot' }}</h2>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            wire:click="clearChat"
                            class="inline-flex h-9 w-9 items-center justify-center border border-white/15 text-slate-200 transition hover:bg-white/10"
                            title="Chat leeren"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <button
                            type="button"
                            x-on:click="showChat = false"
                            class="inline-flex h-9 w-9 items-center justify-center border border-white/15 text-slate-200 transition hover:bg-white/10"
                            title="Schließen"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </header>

            <div class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[1fr_210px]">
                <section class="flex min-h-0 flex-col border-r border-slate-200">
                    <div
                        class="scroll-container min-h-0 flex-1 space-y-3 overflow-y-auto bg-slate-50 px-5 py-5"
                        x-ref="messages"
                        x-init="
                            $watch('chatHistory', () => $nextTick(() => $refs.messages.scrollTo({ top: $refs.messages.scrollHeight, behavior: 'smooth' })));
                            $watch('toolEvents', () => $nextTick(() => $refs.messages.scrollTo({ top: $refs.messages.scrollHeight, behavior: 'smooth' })));
                        "
                    >
                        <template x-if="chatHistory.length === 0">
                            <div class="space-y-4">
                                <div class="border border-slate-200 bg-white p-4">
                                    <p class="text-sm font-black text-slate-950">Bereit fuer Analyse und Steuerung.</p>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">Waehle eine Aktion oder stelle eine konkrete Frage zu Profilen, Scans, Monitoring oder Netzwerkaufbau.</p>
                                </div>

                                <div class="grid gap-2">
                                    <button type="button" @click="quick('Welche Profile sollte ich als naechstes scannen und warum?')" class="border border-slate-200 bg-white px-4 py-3 text-left text-sm font-bold text-slate-800 transition hover:border-emerald-300 hover:bg-emerald-50">
                                        Naechste Scans priorisieren
                                    </button>
                                    <button type="button" @click="quick('Zeige mir meine beobachteten Profile mit Status und schlage die beste Netzwerk-Strategie vor.')" class="border border-slate-200 bg-white px-4 py-3 text-left text-sm font-bold text-slate-800 transition hover:border-emerald-300 hover:bg-emerald-50">
                                        Netzwerk-Strategie erstellen
                                    </button>
                                    <button type="button" @click="quick('Welche Profile sollten ins Monitoring und welches Intervall ist sinnvoll?')" class="border border-slate-200 bg-white px-4 py-3 text-left text-sm font-bold text-slate-800 transition hover:border-emerald-300 hover:bg-emerald-50">
                                        Monitoring bewerten
                                    </button>
                                </div>
                            </div>
                        </template>

                        <template x-for="(item, index) in chatHistory" :key="'message-' + index">
                            <div
                                class="max-w-[92%] border px-4 py-3 text-sm leading-6"
                                :class="item.role === 'user'
                                    ? 'ml-auto border-slate-300 bg-white text-slate-900'
                                    : (item.level === 'error' ? 'border-rose-200 bg-rose-50 text-rose-950' : 'border-emerald-200 bg-white text-slate-800')"
                            >
                                <div class="mb-1 flex items-center justify-between gap-3">
                                    <strong class="text-xs uppercase tracking-[.14em]" x-text="item.role === 'user' ? 'Du' : 'Copilot'"></strong>
                                    <span class="text-[11px] text-slate-400" x-text="item.time"></span>
                                </div>
                                <p class="whitespace-pre-line" x-text="item.content"></p>
                            </div>
                        </template>

                        <template x-if="toolEvents.length > 0">
                            <div class="space-y-2 pt-2">
                                <p class="text-xs font-black uppercase tracking-[.16em] text-slate-500">Ausgefuehrte Tools</p>
                                <template x-for="(event, index) in toolEvents" :key="'tool-' + index">
                                    <div class="border border-slate-200 bg-white px-3 py-2 text-xs text-slate-700">
                                        <div class="flex items-start justify-between gap-3">
                                            <strong x-text="event.tool"></strong>
                                            <span :class="event.ok ? 'text-emerald-700' : 'text-rose-700'" x-text="event.ok ? 'ok' : 'fehler'"></span>
                                        </div>
                                        <p class="mt-1 text-slate-500" x-text="event.message"></p>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <div x-show="isLoading" x-collapse class="max-w-[92%] border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
                            <div class="flex items-center gap-3">
                                <svg class="h-4 w-4 animate-spin text-emerald-700" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle>
                                    <path class="opacity-90" d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                                </svg>
                                <span>Analysiere und pruefe Tools...</span>
                            </div>
                        </div>
                    </div>

                    <footer class="border-t border-slate-200 bg-white p-4">
                        <div class="flex gap-2">
                            <textarea
                                x-model="draft"
                                x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); send(); }"
                                class="min-h-[46px] flex-1 resize-none border border-slate-300 bg-white px-3 py-3 text-sm text-slate-950 focus:border-emerald-500 focus:ring-0"
                                placeholder="Profil analysieren, Scan starten oder naechste Schritte planen..."
                            ></textarea>
                            <button
                                type="button"
                                @click="send()"
                                :disabled="isLoading"
                                class="inline-flex h-[46px] w-[46px] shrink-0 items-center justify-center bg-slate-950 text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                title="Senden"
                            >
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="m5 12 14-7-4 14-3-6-7-1Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                </svg>
                            </button>
                        </div>
                    </footer>
                </section>

                <aside class="hidden min-h-0 overflow-y-auto bg-white p-4 lg:block">
                    <p class="text-xs font-black uppercase tracking-[.16em] text-slate-500">Profile</p>
                    <div class="mt-3 space-y-2">
                        @forelse($trackedPeople as $trackedPerson)
                            <button
                                type="button"
                                @click="quick(@js('Analysiere '.($trackedPerson->instagram_username ? '@'.$trackedPerson->instagram_username : $trackedPerson->display_name).' und empfehle die naechsten Scans.'))"
                                class="w-full border border-slate-200 px-3 py-3 text-left transition hover:border-emerald-300 hover:bg-emerald-50"
                            >
                                <div class="truncate text-sm font-black text-slate-950">{{ $trackedPerson->display_name }}</div>
                                <div class="mt-1 truncate text-xs text-slate-500">
                                    {{ $trackedPerson->instagram_username ? '@'.$trackedPerson->instagram_username : 'kein Instagram' }}
                                </div>
                                <div class="mt-2 text-[11px] font-bold {{ $trackedPerson->last_instagram_status_level === 'error' ? 'text-rose-700' : 'text-emerald-700' }}">
                                    {{ $trackedPerson->last_instagram_status_message ?: 'Noch kein Scan' }}
                                </div>
                            </button>
                        @empty
                            <div class="border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-600">
                                Noch keine beobachteten Profile.
                            </div>
                        @endforelse
                    </div>
                </aside>
            </div>
        </aside>
    @endif
</div>
