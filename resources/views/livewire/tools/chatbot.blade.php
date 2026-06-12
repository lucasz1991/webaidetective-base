<div
    x-data="{
        showChat: $persist(false).using(sessionStorage),
        draft: @entangle('message'),
        isLoading: @entangle('isLoading'),
        chatHistory: @entangle('chatHistory'),
        toolEvents: @entangle('toolEvents'),
        pageContext: @js($pageContext),
        voiceSupported: false,
        listening: false,
        recognition: null,
        init() {
            this.voiceSupported = ('SpeechRecognition' in window) || ('webkitSpeechRecognition' in window);
            this.syncContext();
        },
        normalizeEventDetail(event) {
            return Array.isArray(event?.detail) ? (event.detail[0] || {}) : (event?.detail || {});
        },
        collectContext(extra = {}) {
            const mapRoot = document.querySelector('[data-network-map-root]');
            const path = window.location.pathname;
            const trackedPersonMatch = path.match(/\/user\/tracked-people\/(\d+)/);
            const instagramProfileMatch = path.match(/\/user\/instagram-profiles\/(\d+)/);
            const routeName = trackedPersonMatch
                ? 'tracked-people.show'
                : (instagramProfileMatch ? 'instagram-profiles.show' : (path === '/user/network' ? 'network' : null));

            return {
                ...this.pageContext,
                route_name: routeName,
                path,
                page_title: document.title,
                tracked_person_id: trackedPersonMatch ? trackedPersonMatch[1] : null,
                instagram_profile_id: instagramProfileMatch ? instagramProfileMatch[1] : null,
                network_map_open: Boolean(mapRoot),
                network_map_id: mapRoot?.dataset.networkMapId || null,
                network_focus_tracked_person_id: mapRoot?.dataset.networkFocusTrackedPersonId || null,
                ...extra,
            };
        },
        async syncContext(extra = {}) {
            this.pageContext = this.collectContext(extra);
            await $wire.updatePageContext(this.pageContext);
        },
        async openChat() {
            this.showChat = true;
            await this.syncContext();
        },
        async send() {
            if (this.isLoading) return;
            await this.syncContext();
            $wire.set('message', this.draft || '');
            $wire.sendMessage();
        },
        async quick(prompt) {
            if (this.isLoading) return;
            await this.syncContext();
            this.draft = prompt;
            $wire.set('message', prompt);
            $wire.sendMessage();
        },
        selectNetworkNode(event) {
            const detail = this.normalizeEventDetail(event);
            const handle = String(detail.handle || '').replace(/^@/, '');

            this.syncContext({
                selected_node_id: detail.id || null,
                selected_node_type: detail.type || null,
                selected_profile_username: handle || null,
                selected_profile_name: detail.name || null,
                selected_profile_open: false,
            });
        },
        updateNetworkContext(event) {
            const detail = this.normalizeEventDetail(event);

            this.syncContext({
                network_map_open: detail.open !== false,
                network_map_fullscreen: Boolean(detail.fullscreen),
                network_map_id: detail.mapId || null,
                network_focus_tracked_person_id: detail.focusTrackedPersonId || null,
            });
        },
        updateProfilePreview(event) {
            const detail = this.normalizeEventDetail(event);

            this.syncContext({
                instagram_profile_id: detail.open ? (detail.instagramProfileId || null) : null,
                selected_node_id: detail.open ? (detail.nodeId || this.pageContext.selected_node_id || null) : this.pageContext.selected_node_id,
                selected_profile_username: detail.open ? (detail.username || null) : this.pageContext.selected_profile_username,
                selected_profile_name: detail.open ? (detail.name || null) : this.pageContext.selected_profile_name,
                selected_profile_open: Boolean(detail.open),
            });
        },
        contextLabel() {
            if (this.pageContext.selected_profile_name || this.pageContext.selected_profile_username) {
                return `Kontext: ${this.pageContext.selected_profile_name || '@' + this.pageContext.selected_profile_username}`;
            }

            if (this.pageContext.network_map_open) {
                return this.pageContext.network_map_fullscreen ? 'Kontext: Networkmap im Vollbild' : 'Kontext: Networkmap';
            }

            return `Kontext: ${this.pageContext.page_title || this.pageContext.path || 'aktuelle Seite'}`;
        },
        toggleVoice() {
            if (!this.voiceSupported || this.isLoading) return;

            if (!this.recognition) {
                const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
                this.recognition = new SpeechRecognition();
                this.recognition.lang = 'de-DE';
                this.recognition.continuous = false;
                this.recognition.interimResults = false;
                this.recognition.onresult = (event) => {
                    let transcript = '';

                    for (let i = event.resultIndex; i < event.results.length; i++) {
                        transcript += event.results[i][0].transcript;
                    }

                    this.draft = `${this.draft || ''} ${transcript}`.trim();
                    $wire.set('message', this.draft);
                };
                this.recognition.onend = () => this.listening = false;
                this.recognition.onerror = () => this.listening = false;
            }

            if (this.listening) {
                this.recognition.stop();
                this.listening = false;
                return;
            }

            this.listening = true;
            this.recognition.start();
        }
    }"
    x-on:network-map-node-selected.window="selectNetworkNode($event)"
    x-on:assistant-network-context.window="updateNetworkContext($event)"
    x-on:assistant-context-profile-preview.window="updateProfilePreview($event)"
    x-on:livewire:navigated.window="syncContext({
        selected_node_id: null,
        selected_node_type: null,
        selected_profile_username: null,
        selected_profile_name: null,
        selected_profile_open: false,
        network_map_fullscreen: false
    })"
    class="investigation-copilot"
>
    @if($status)
        <button
            x-show="!showChat"
            x-cloak
            x-on:click="openChat()"
            class="fixed bottom-5 right-5 z-50 flex h-14 w-14 items-center justify-center rounded-full bg-slate-950 text-white shadow-2xl shadow-slate-900/30 transition hover:bg-emerald-700"
            aria-label="Investigation Copilot öffnen"
        >
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 3 4 7v6c0 4.5 3.4 7.4 8 8 4.6-.6 8-3.5 8-8V7l-8-4Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                <path d="M9 12h6M12 9v6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </button>

        <aside
            x-show="showChat"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-3 scale-95 opacity-0"
            x-transition:enter-end="translate-y-0 scale-100 opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-y-0 scale-100 opacity-100"
            x-transition:leave-end="translate-y-3 scale-95 opacity-0"
            class="fixed bottom-4 right-4 z-50 flex h-[min(680px,calc(100vh-2rem))] w-[min(430px,calc(100vw-2rem))] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-950/25"
        >
            <header class="border-b border-slate-200 bg-slate-950 px-4 py-3 text-white">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-xs font-black uppercase tracking-[.18em] text-emerald-300">AI Tool</p>
                        <h2 class="mt-0.5 truncate text-lg font-black">{{ $assistantName ?: 'Investigation Copilot' }}</h2>
                        <p class="mt-1 truncate text-[11px] text-slate-300" x-text="contextLabel()"></p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            wire:click="clearChat"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-white/15 text-slate-200 transition hover:bg-white/10"
                            title="Chat leeren"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <button
                            type="button"
                            x-on:click="showChat = false"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-white/15 text-slate-200 transition hover:bg-white/10"
                            title="Schließen"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </header>

            <div class="grid min-h-0 flex-1 grid-cols-1">
                <section class="flex min-h-0 flex-col">
                    <div
                        class="scroll-container min-h-0 flex-1 space-y-3 overflow-y-auto bg-slate-50 px-4 py-4"
                        x-ref="messages"
                        x-init="
                            $watch('chatHistory', () => $nextTick(() => $refs.messages.scrollTo({ top: $refs.messages.scrollHeight, behavior: 'smooth' })));
                            $watch('toolEvents', () => $nextTick(() => $refs.messages.scrollTo({ top: $refs.messages.scrollHeight, behavior: 'smooth' })));
                        "
                    >
                        <template x-if="chatHistory.length === 0">
                            <div class="space-y-4">
                                <div class="rounded-xl border border-slate-200 bg-white p-4">
                                    <p class="text-sm font-black text-slate-950">Bereit fuer Analyse und Steuerung.</p>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">Die aktuelle Seite, eine offene Networkmap und das ausgewaehlte Profil werden automatisch als Kontext verwendet.</p>
                                </div>

                                <div class="grid gap-2">
                                    <button type="button" @click="quick('Welche Profile sollte ich als naechstes scannen und warum?')" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-left text-sm font-bold text-slate-800 transition hover:border-emerald-300 hover:bg-emerald-50">
                                        Naechste Scans priorisieren
                                    </button>
                                    <button type="button" @click="quick('Zeige mir meine beobachteten Profile mit Status und schlage die beste Netzwerk-Strategie vor.')" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-left text-sm font-bold text-slate-800 transition hover:border-emerald-300 hover:bg-emerald-50">
                                        Netzwerk-Strategie erstellen
                                    </button>
                                    <button type="button" @click="quick('Welche Profile sollten ins Monitoring und welches Intervall ist sinnvoll?')" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-left text-sm font-bold text-slate-800 transition hover:border-emerald-300 hover:bg-emerald-50">
                                        Monitoring bewerten
                                    </button>
                                    <button type="button" @click="quick('Analysiere den gespeicherten Profilgraphen und liste die wichtigsten Kontaktkandidaten fuer den naechsten Scan.')" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-left text-sm font-bold text-slate-800 transition hover:border-emerald-300 hover:bg-emerald-50">
                                        Kontaktkandidaten finden
                                    </button>
                                </div>
                            </div>
                        </template>

                        <template x-for="(item, index) in chatHistory" :key="'message-' + index">
                            <div
                                class="max-w-[92%] rounded-xl border px-4 py-3 text-sm leading-6"
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
                                    <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-700">
                                        <div class="flex items-start justify-between gap-3">
                                            <strong x-text="event.tool"></strong>
                                            <span :class="event.ok ? 'text-emerald-700' : 'text-rose-700'" x-text="event.ok ? 'ok' : 'fehler'"></span>
                                        </div>
                                        <p class="mt-1 text-slate-500" x-text="event.message"></p>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <div x-show="isLoading" x-collapse class="max-w-[92%] rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
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
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <input
                                x-ref="fileInput"
                                type="file"
                                wire:model="uploads"
                                multiple
                                class="hidden"
                                accept=".txt,.csv,.json,.md,.log,.xml,.html,.yaml,.yml,.pdf,.png,.jpg,.jpeg,.webp"
                            >
                            <button
                                type="button"
                                x-on:click="$refs.fileInput.click()"
                                :disabled="isLoading"
                                class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-300 px-3 text-xs font-black uppercase tracking-[.12em] text-slate-700 transition hover:border-emerald-400 hover:text-emerald-800 disabled:opacity-50"
                                title="Dateien hinzufuegen"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                                Datei
                            </button>
                            <button
                                type="button"
                                x-on:click="toggleVoice()"
                                :disabled="!voiceSupported || isLoading"
                                class="inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-xs font-black uppercase tracking-[.12em] transition disabled:cursor-not-allowed disabled:opacity-40"
                                :class="listening ? 'border-rose-300 bg-rose-50 text-rose-700' : 'border-slate-300 text-slate-700 hover:border-emerald-400 hover:text-emerald-800'"
                                title="Spracheingabe"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3Z" stroke="currentColor" stroke-width="2"/>
                                    <path d="M5 11a7 7 0 0 0 14 0M12 18v3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                                <span x-text="listening ? 'Hoert zu' : 'Sprache'"></span>
                            </button>
                            <span wire:loading wire:target="uploads" class="text-xs font-bold text-slate-500">Dateien werden vorbereitet...</span>
                            @if(count($uploads) > 0)
                                <span class="text-xs font-bold text-emerald-700">{{ count($uploads) }} Datei(en) bereit</span>
                            @endif
                        </div>

                        <div class="flex gap-2">
                            <textarea
                                x-model="draft"
                                x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); send(); }"
                                class="min-h-[54px] flex-1 resize-none rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-950 focus:border-emerald-500 focus:ring-0"
                                placeholder="Profil analysieren, Kontakt speichern, Scan starten oder naechste Schritte planen..."
                            ></textarea>
                            <button
                                type="button"
                                @click="send()"
                                :disabled="isLoading"
                                class="inline-flex h-[54px] w-[54px] shrink-0 items-center justify-center rounded-xl bg-slate-950 text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                title="Senden"
                            >
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="m5 12 14-7-4 14-3-6-7-1Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                </svg>
                            </button>
                        </div>
                    </footer>
                </section>
            </div>
        </aside>
    @endif
</div>
