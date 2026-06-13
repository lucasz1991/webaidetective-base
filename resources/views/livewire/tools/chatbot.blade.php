<div
    x-data="{
        showChat: $persist(false).using(sessionStorage),
        draft: @entangle('message'),
        isLoading: @entangle('isLoading'),
        chatHistory: @entangle('chatHistory'),
        toolEvents: @entangle('toolEvents'),
        attachedFiles: @entangle('uploads'),
        pageContext: @js($pageContext),
        voiceSupported: false,
        listening: false,
        recognition: null,
        speechSupported: false,
        speaking: false,
        speakingIndex: null,
        autoRead: $persist(false).as('investigation-copilot-auto-read'),
        speechRate: $persist(1).as('investigation-copilot-speech-rate'),
        lastAssistantMessageKey: null,
        init() {
            this.voiceSupported = ('SpeechRecognition' in window) || ('webkitSpeechRecognition' in window);
            this.speechSupported = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;
            this.lastAssistantMessageKey = this.latestAssistantMessageKey(this.chatHistory);
            this.$watch('chatHistory', (history) => this.handleNewAssistantMessage(history));
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
            if (!(this.draft || '').trim() && !this.hasUploads()) return;
            await this.syncContext();
            $wire.set('message', this.draft || '');
            await $wire.sendMessage();
            this.$nextTick(() => this.resizeComposer());
        },
        async quick(prompt) {
            if (this.isLoading) return;
            await this.syncContext();
            this.draft = prompt;
            $wire.set('message', prompt);
            await $wire.sendMessage();
            this.$nextTick(() => this.resizeComposer());
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
        hasUploads() {
            return (Array.isArray(this.attachedFiles) && this.attachedFiles.length > 0)
                || Boolean(this.$refs.fileInput?.files?.length);
        },
        resizeComposer() {
            const composer = this.$refs.composer;
            if (!composer) return;
            composer.style.height = 'auto';
            composer.style.height = `${Math.min(composer.scrollHeight, 144)}px`;
        },
        latestAssistantMessageKey(history) {
            const messages = Array.isArray(history) ? history : [];
            const item = [...messages].reverse().find((message) => message?.role === 'assistant');
            return item ? `${item.time || ''}|${item.content || ''}` : null;
        },
        handleNewAssistantMessage(history) {
            const messages = Array.isArray(history) ? history : [];
            const index = messages.map((item) => item?.role).lastIndexOf('assistant');
            if (index < 0) return;

            const item = messages[index];
            const key = `${item.time || ''}|${item.content || ''}`;
            if (key === this.lastAssistantMessageKey) return;

            this.lastAssistantMessageKey = key;
            if (this.autoRead && item.content) {
                this.speak(item.content, index);
            }
        },
        speak(text, index = null) {
            if (!this.speechSupported || !text) return;

            window.speechSynthesis.cancel();
            const utterance = new SpeechSynthesisUtterance(String(text));
            utterance.lang = 'de-DE';
            utterance.rate = Number(this.speechRate || 1);
            utterance.pitch = 1;
            utterance.onstart = () => {
                this.speaking = true;
                this.speakingIndex = index;
            };
            utterance.onend = () => {
                this.speaking = false;
                this.speakingIndex = null;
            };
            utterance.onerror = () => {
                this.speaking = false;
                this.speakingIndex = null;
            };
            window.speechSynthesis.speak(utterance);
        },
        stopSpeaking() {
            if (!this.speechSupported) return;
            window.speechSynthesis.cancel();
            this.speaking = false;
            this.speakingIndex = null;
        },
        handleUiAction(event) {
            const detail = this.normalizeEventDetail(event);
            const action = detail.action || detail;

            if (action?.type === 'navigate' && action.url) {
                window.setTimeout(() => this.navigateTo(action.url), 850);
            }
        },
        navigateTo(url) {
            if (!url) return;
            this.stopSpeaking();

            if (window.Livewire?.navigate) {
                window.Livewire.navigate(url);
                return;
            }

            window.location.assign(url);
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
                    this.$nextTick(() => this.resizeComposer());
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
    x-on:assistant-ui-action.window="handleUiAction($event)"
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
                        <x-ui.dropdown.anchor-dropdown
                            align="right"
                            width="auto"
                            :offset="8"
                            dropdown-classes=""
                            content-classes="w-72 rounded-xl border border-slate-200 bg-white text-slate-900"
                        >
                            <x-slot name="trigger">
                                <button
                                    type="button"
                                    x-bind:aria-expanded="open"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-white/15 text-slate-200 transition hover:bg-white/10"
                                    title="Sprach-Einstellungen"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" stroke="currentColor" stroke-width="2"/>
                                        <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21h-4v-.09A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3v-4h.09A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.09A1.7 1.7 0 0 0 15.4 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.1.38.31.73.6 1 .3.27.68.41 1.09.4H21v4h-.09A1.7 1.7 0 0 0 19.4 15Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <div class="space-y-4 p-4 text-left">
                                    <div>
                                        <p class="text-xs font-black uppercase tracking-[.14em] text-slate-500">Sprachausgabe</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">Antworten einzeln oder automatisch mit der Browserstimme vorlesen.</p>
                                    </div>

                                    <div class="flex items-center justify-between gap-4">
                                        <div>
                                            <p class="text-sm font-bold text-slate-800">Automatisch vorlesen</p>
                                            <p class="text-[11px] text-slate-500">Nur neue Copilot-Antworten</p>
                                        </div>
                                        <x-ui.forms.toggle-button
                                            id="assistant-auto-read"
                                            alpine-model="autoRead"
                                        />
                                    </div>

                                    <div>
                                        <label for="assistant-speech-rate" class="mb-1 block text-xs font-bold text-slate-600">Sprechgeschwindigkeit</label>
                                        <select
                                            id="assistant-speech-rate"
                                            x-model.number="speechRate"
                                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 focus:border-emerald-500 focus:ring-emerald-500"
                                        >
                                            <option value="0.8">Ruhig</option>
                                            <option value="1">Normal</option>
                                            <option value="1.2">Schnell</option>
                                            <option value="1.4">Sehr schnell</option>
                                        </select>
                                    </div>

                                    <div class="flex gap-2">
                                        <button
                                            type="button"
                                            x-on:click="speak('Die Sprachausgabe ist einsatzbereit.')"
                                            x-bind:disabled="!speechSupported"
                                            class="inline-flex flex-1 items-center justify-center rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                                        >
                                            Stimme testen
                                        </button>
                                        <button
                                            type="button"
                                            x-show="speaking"
                                            x-on:click="stopSpeaking()"
                                            class="inline-flex items-center justify-center rounded-lg bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700"
                                        >
                                            Stoppen
                                        </button>
                                    </div>
                                </div>
                            </x-slot>
                        </x-ui.dropdown.anchor-dropdown>

                        <button
                            type="button"
                            wire:click="clearChat"
                            x-on:click="stopSpeaking()"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-white/15 text-slate-200 transition hover:bg-white/10"
                            title="Chat leeren"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <button
                            type="button"
                            x-on:click="stopSpeaking(); showChat = false"
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
                                    <div class="flex items-center gap-2">
                                        <button
                                            type="button"
                                            x-show="item.role === 'assistant' && speechSupported"
                                            x-on:click="speaking && speakingIndex === index ? stopSpeaking() : speak(item.content, index)"
                                            class="inline-flex h-6 w-6 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-emerald-700"
                                            x-bind:title="speaking && speakingIndex === index ? 'Vorlesen stoppen' : 'Antwort vorlesen'"
                                        >
                                            <svg x-show="!(speaking && speakingIndex === index)" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M5 9v6h4l5 4V5L9 9H5Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                                <path d="M17 9a4 4 0 0 1 0 6M19.5 6.5a7.5 7.5 0 0 1 0 11" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                            </svg>
                                            <svg x-show="speaking && speakingIndex === index" class="h-3.5 w-3.5 text-rose-600" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                <rect x="6" y="6" width="12" height="12" rx="2"/>
                                            </svg>
                                        </button>
                                        <span class="text-[11px] text-slate-400" x-text="item.time"></span>
                                    </div>
                                </div>
                                <p class="whitespace-pre-line" x-text="item.content"></p>

                                <div x-show="Array.isArray(item.profiles) && item.profiles.length" class="mt-3 flex flex-wrap gap-2">
                                    <template x-for="profile in (item.profiles || [])" :key="profile.type + '-' + profile.id">
                                        <button
                                            type="button"
                                            x-on:click="navigateTo(profile.url)"
                                            class="group inline-flex max-w-full items-center gap-2 rounded-full border border-slate-200 bg-slate-50 py-1 pl-1 pr-3 text-left transition hover:border-emerald-300 hover:bg-emerald-50"
                                            x-bind:title="'Profil öffnen: @' + profile.username"
                                        >
                                            <span class="relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-200 text-xs font-black uppercase text-slate-600 ring-1 ring-white">
                                                <span x-text="(profile.display_name || profile.username || '?').charAt(0)"></span>
                                                <img
                                                    x-show="profile.image_url"
                                                    x-bind:src="profile.image_url"
                                                    x-bind:alt="'Profilbild von @' + profile.username"
                                                    x-on:error="$el.remove()"
                                                    class="absolute inset-0 h-full w-full object-cover"
                                                    loading="lazy"
                                                    referrerpolicy="no-referrer"
                                                >
                                            </span>
                                            <span class="min-w-0">
                                                <span class="block truncate text-xs font-black text-slate-800 group-hover:text-emerald-800" x-text="profile.display_name || '@' + profile.username"></span>
                                                <span class="block truncate text-[10px] text-slate-500" x-text="'@' + profile.username"></span>
                                            </span>
                                        </button>
                                    </template>
                                </div>
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

                        <div x-show="isLoading" x-collapse class="max-w-[92%] overflow-hidden rounded-xl border border-emerald-200 bg-white px-4 py-3 text-sm text-slate-600 shadow-sm">
                            <div class="flex items-center gap-3">
                                <span class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-50">
                                    <span class="absolute inset-1 animate-ping rounded-full bg-emerald-300/40"></span>
                                    <svg class="relative h-4 w-4 animate-spin text-emerald-700" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle class="opacity-20" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle>
                                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <p class="font-bold text-slate-800">Copilot arbeitet</p>
                                    <div class="mt-1 flex items-center gap-1 text-xs text-slate-500">
                                        <span>Kontext und Tools werden geprüft</span>
                                        <span class="flex gap-1" aria-hidden="true">
                                            <span class="h-1 w-1 animate-bounce rounded-full bg-emerald-500 [animation-delay:-.3s]"></span>
                                            <span class="h-1 w-1 animate-bounce rounded-full bg-emerald-500 [animation-delay:-.15s]"></span>
                                            <span class="h-1 w-1 animate-bounce rounded-full bg-emerald-500"></span>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 h-1 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full w-2/3 animate-pulse rounded-full bg-gradient-to-r from-emerald-400 via-cyan-400 to-emerald-400"></div>
                            </div>
                        </div>
                    </div>

                    <footer class="border-t border-slate-200 bg-white p-3">
                        <input
                            x-ref="fileInput"
                            type="file"
                            wire:model="uploads"
                            multiple
                            class="hidden"
                            accept=".txt,.csv,.json,.md,.log,.xml,.html,.yaml,.yml,.pdf,.png,.jpg,.jpeg,.webp"
                        >

                        <div
                            class="overflow-hidden rounded-2xl border bg-white shadow-sm transition focus-within:border-emerald-400 focus-within:ring-4 focus-within:ring-emerald-100"
                            :class="listening ? 'border-rose-300 ring-4 ring-rose-100' : 'border-slate-300'"
                        >
                            <div
                                x-show="listening"
                                x-collapse
                                class="flex items-center gap-2 border-b border-rose-100 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700"
                            >
                                <span class="relative flex h-2 w-2">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-rose-400 opacity-75"></span>
                                    <span class="relative inline-flex h-2 w-2 rounded-full bg-rose-500"></span>
                                </span>
                                Spracheingabe aktiv. Ich höre zu.
                            </div>

                            @if(count($uploads) > 0)
                                <div class="flex items-center gap-2 border-b border-slate-100 bg-slate-50 px-3 py-2">
                                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="M8 12.5 12 16l6-7M7 3h7l4 4v14H7V3Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </span>
                                    <span class="min-w-0 flex-1 truncate text-xs font-bold text-slate-700">{{ count($uploads) }} Datei(en) für die Analyse bereit</span>
                                    <button type="button" wire:click="$set('uploads', [])" class="text-xs font-bold text-slate-400 hover:text-rose-600">Entfernen</button>
                                </div>
                            @endif

                            <textarea
                                x-ref="composer"
                                x-model="draft"
                                x-init="$nextTick(() => resizeComposer())"
                                x-on:input="resizeComposer()"
                                x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); send(); }"
                                rows="1"
                                class="block min-h-[58px] max-h-36 w-full resize-none border-0 bg-transparent px-4 pb-2 pt-3 text-sm leading-6 text-slate-950 placeholder:text-slate-400 focus:ring-0"
                                placeholder="Frage stellen, Profil öffnen oder Analyse starten..."
                            ></textarea>

                            <div class="flex items-center justify-between gap-3 px-2 pb-2">
                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        x-on:click="$refs.fileInput.click()"
                                        :disabled="isLoading"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-emerald-700 disabled:opacity-40"
                                        title="Dateien hinzufügen"
                                    >
                                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="m12.5 6.5-6.8 6.8a3.2 3.2 0 0 0 4.5 4.5l7.6-7.6a4.5 4.5 0 0 0-6.4-6.4L4.6 10.6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        x-on:click="toggleVoice()"
                                        :disabled="!voiceSupported || isLoading"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-xl transition disabled:cursor-not-allowed disabled:opacity-30"
                                        :class="listening ? 'bg-rose-100 text-rose-700' : 'text-slate-500 hover:bg-slate-100 hover:text-emerald-700'"
                                        :title="voiceSupported ? 'Spracheingabe' : 'Spracheingabe wird nicht unterstützt'"
                                    >
                                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3Z" stroke="currentColor" stroke-width="2"/>
                                            <path d="M5 11a7 7 0 0 0 14 0M12 18v3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                        </svg>
                                    </button>

                                    <span wire:loading wire:target="uploads" class="ml-1 inline-flex items-center gap-2 text-[11px] font-bold text-slate-500">
                                        <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <circle class="opacity-20" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle>
                                            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                                        </svg>
                                        Upload
                                    </span>
                                </div>

                                <div class="flex items-center gap-2">
                                    <span class="hidden text-[10px] font-semibold text-slate-400 sm:inline">Enter senden · Shift+Enter Zeile</span>
                                    <button
                                        type="button"
                                        @click="send()"
                                        :disabled="isLoading || (!(draft || '').trim() && !hasUploads())"
                                        class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-950 text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                                        title="Senden"
                                    >
                                        <svg x-show="!isLoading" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="m5 12 14-7-4 14-3-6-7-1Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                        </svg>
                                        <svg x-show="isLoading" class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle>
                                            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <p class="mt-2 px-1 text-[10px] leading-4 text-slate-400">
                            Der Copilot kann freigegebene Profile und App-Seiten öffnen. Aktionen werden im Chat protokolliert.
                        </p>
                    </footer>
                </section>
            </div>
        </aside>
    @endif
</div>
