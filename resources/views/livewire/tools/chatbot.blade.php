<div
    x-data="{
        showChat: $persist(false).using(sessionStorage),
        draft: @entangle('message'),
        isLoading: @entangle('isLoading'),
        chatHistory: @entangle('chatHistory'),
        toolEvents: @entangle('toolEvents'),
        scanActivities: @entangle('scanActivities'),
        attachedFiles: @entangle('uploads'),
        pageContext: @js($pageContext),
        submitting: false,
        pendingLabel: '',
        selectedChatOptions: {},
        resumingScanToken: null,
        voiceSupported: false,
        listening: false,
        recognition: null,
        ttsEndpoint: @js(route('assistant.audio-output.stream', [], false)),
        csrfToken: @js(csrf_token()),
        ttsAudio: null,
        ttsError: '',
        ttsQueue: [],
        ttsPlaying: false,
        ttsAbortController: null,
        ttsObjectUrls: [],
        ttsStreamBuffer: '',
        ttsStreamLastText: '',
        ttsStreamObserver: null,
        ttsStreamFallbackTimer: null,
        ttsCurrentGeneration: 0,
        speechSupported: false,
        speaking: false,
        speakingIndex: null,
        autoRead: $persist(false).as('investigation-copilot-auto-read'),
        speechRate: $persist(1).as('investigation-copilot-speech-rate'),
        lastAssistantMessageKey: null,
        toolAlertTimers: {},
        init() {
            this.voiceSupported = ('SpeechRecognition' in window) || ('webkitSpeechRecognition' in window);
            this.speechSupported = 'fetch' in window && 'Audio' in window && 'URL' in window;
            this.lastAssistantMessageKey = this.latestAssistantMessageKey(this.chatHistory);
            this.$watch('chatHistory', (history) => this.handleNewAssistantMessage(history));
            this.$watch('isLoading', (loading) => {
                if (loading) {
                    this.beginTtsStream();
                } else {
                    this.finishTtsStream();
                }
            });
            this.$watch('autoRead', (enabled) => {
                if (!enabled) {
                    this.stopSpeaking();
                } else if (this.isLoading) {
                    this.beginTtsStream();
                }
            });
            this.$watch('toolEvents', (events) => this.scheduleToolAlerts(events));
            this.scheduleToolAlerts(this.toolEvents);
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
        busy() {
            return this.submitting || this.isLoading;
        },
        scrollMessages() {
            this.$nextTick(() => {
                const messages = this.$refs.messages;
                if (!messages) return;
                messages.scrollTo({ top: messages.scrollHeight, behavior: 'smooth' });
            });
        },
        async send() {
            if (this.busy()) return;
            if (!(this.draft || '').trim() && !this.hasUploads()) return;

            const outgoingMessage = this.draft || '';
            this.submitting = true;
            this.pendingLabel = outgoingMessage.trim() || 'Dateien werden analysiert.';
            this.scrollMessages();

            try {
                await this.syncContext();
                this.draft = '';
                this.$nextTick(() => this.resizeComposer());
                await $wire.sendMessage(outgoingMessage);
            } finally {
                this.submitting = false;
                this.pendingLabel = '';
                this.$nextTick(() => this.resizeComposer());
                this.scrollMessages();
            }
        },
        async quick(prompt) {
            if (this.busy()) return;

            this.submitting = true;
            this.pendingLabel = prompt;
            this.scrollMessages();

            try {
                await this.syncContext();
                this.draft = '';
                await $wire.sendMessage(prompt);
            } finally {
                this.submitting = false;
                this.pendingLabel = '';
                this.$nextTick(() => this.resizeComposer());
                this.scrollMessages();
            }
        },
        selectedChatOptionIndex(item, messageIndex) {
            if (Object.prototype.hasOwnProperty.call(this.selectedChatOptions, messageIndex)) {
                return this.selectedChatOptions[messageIndex];
            }

            if (item?.selected_option_index === null || item?.selected_option_index === undefined) {
                return null;
            }

            const storedIndex = Number(item?.selected_option_index);

            return Number.isInteger(storedIndex) && storedIndex >= 0 ? storedIndex : null;
        },
        async chooseChatOption(item, messageIndex, option, optionIndex) {
            if (this.busy() || this.selectedChatOptionIndex(item, messageIndex) !== null) return;

            this.selectedChatOptions = {
                ...this.selectedChatOptions,
                [messageIndex]: optionIndex,
            };
            this.submitting = true;
            this.pendingLabel = option.prompt;
            this.scrollMessages();

            try {
                await this.syncContext();
                this.draft = '';
                await $wire.sendChatOption(messageIndex, optionIndex);
            } finally {
                const remainingSelections = { ...this.selectedChatOptions };
                delete remainingSelections[messageIndex];
                this.selectedChatOptions = remainingSelections;
                this.submitting = false;
                this.pendingLabel = '';
                this.$nextTick(() => this.resizeComposer());
                this.scrollMessages();
            }
        },
        async requestProfileScan(profile) {
            if (this.busy()) return;

            const username = String(profile?.username || '').replace(/^@/, '').trim();
            if (!username) return;

            const profileType = String(profile?.type || 'instagram_profile');
            const profileId = Number(profile?.id || 0) || null;
            const displayName = String(profile?.display_name || `@${username}`);

            this.stopSpeaking();
            const prompt = [
                '[SCAN_TARGET_SELECTED]',
                `Ich habe den Profilvorschlag @${username} als Scan-Ziel ausgewählt.`,
                `Profiltyp: ${profileType}.`,
                'Prüfe bitte zuerst die vorhandenen Profildaten und die Scan-Historie.',
                'Falls ich noch keinen eindeutigen Scan-Typ bestätigt habe, frage mich, welchen Scan ich starten möchte, und nenne kurz die passenden Optionen.',
                'Starte noch keinen Scan ohne meine konkrete Auswahl.',
            ].join('\n');

            this.submitting = true;
            this.pendingLabel = `Scan für @${username} auswählen.`;
            this.scrollMessages();

            try {
                await this.syncContext({
                    selected_node_id: profileId
                        ? (profileType === 'tracked_person' ? `person-${profileId}` : `instagram-profile-${profileId}`)
                        : null,
                    selected_node_type: profileType,
                    selected_profile_username: username,
                    selected_profile_name: displayName,
                    selected_profile_open: false,
                });
                this.draft = '';
                await $wire.sendMessage(prompt);
            } finally {
                this.submitting = false;
                this.pendingLabel = '';
                this.$nextTick(() => this.resizeComposer());
                this.scrollMessages();
            }
        },
        async requestProfileScanType(profile, scanType, scanLabel) {
            if (this.busy()) return;

            const username = String(profile?.username || '').replace(/^@/, '').trim();
            if (!username || !scanType) return;

            const profileType = String(profile?.type || 'instagram_profile');
            const profileId = Number(profile?.id || 0) || null;
            const displayName = String(profile?.display_name || `@${username}`);
            const label = String(scanLabel || scanType);
            const prompt = [
                '[SCAN_TYPE_CONFIRMED]',
                `Starte jetzt für @${username} den Scan-Typ ${scanType}.`,
                `Ausgewählte Aktion: ${label}.`,
                `Profiltyp: ${profileType}.`,
                'Diese Auswahl ist meine ausdrückliche Bestätigung für genau diesen Scan.',
                'Nutze das passende Scan-Tool und starte keinen anderen Scan-Typ.',
            ].join('\n');

            this.stopSpeaking();
            this.submitting = true;
            this.pendingLabel = `${label} für @${username} starten.`;
            this.scrollMessages();

            try {
                await this.syncContext({
                    selected_node_id: profileId
                        ? (profileType === 'tracked_person' ? `person-${profileId}` : `instagram-profile-${profileId}`)
                        : null,
                    selected_node_type: profileType,
                    selected_profile_username: username,
                    selected_profile_name: displayName,
                    selected_profile_open: false,
                });
                this.draft = '';
                await $wire.sendMessage(prompt);
            } finally {
                this.submitting = false;
                this.pendingLabel = '';
                this.$nextTick(() => this.resizeComposer());
                this.scrollMessages();
            }
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
            if (this.autoRead && item.content && !this.ttsStreamLastText.trim()) {
                this.speak(item.content, index);
            }
        },
        speak(text, index = null) {
            if (!this.speechSupported || !text) return;

            this.stopSpeaking();
            this.ttsError = '';
            this.queueTtsSentence(String(text), index);
        },
        beginTtsStream() {
            if (!this.autoRead || !this.speechSupported) return;

            this.stopSpeaking();
            this.ttsStreamBuffer = '';
            this.ttsStreamLastText = '';
            this.ttsCurrentGeneration++;
            this.observeTtsStream();
        },
        observeTtsStream() {
            this.$nextTick(() => {
                const el = this.$refs.assistantResponseStream;
                if (!el) return;

                if (this.ttsStreamObserver) {
                    this.ttsStreamObserver.disconnect();
                    this.ttsStreamObserver = null;
                }

                this.ttsStreamLastText = el.textContent || '';
                this.ttsStreamObserver = new MutationObserver(() => this.consumeTtsStreamText());
                this.ttsStreamObserver.observe(el, {
                    childList: true,
                    characterData: true,
                    subtree: true,
                });

                this.consumeTtsStreamText();
            });
        },
        consumeTtsStreamText() {
            if (!this.autoRead || !this.speechSupported) return;

            const el = this.$refs.assistantResponseStream;
            if (!el) return;

            const fullText = el.textContent || '';
            let delta = '';

            if (fullText.startsWith(this.ttsStreamLastText)) {
                delta = fullText.slice(this.ttsStreamLastText.length);
            } else {
                delta = fullText;
            }

            this.ttsStreamLastText = fullText;

            if (delta) {
                this.enqueueTtsText(delta);
            }
        },
        enqueueTtsText(delta) {
            this.ttsStreamBuffer += String(delta || '').replace(/\s+/g, ' ');

            const sentencePattern = /^([\s\S]*?[.!?…](?:\s+|$))/u;
            let match = this.ttsStreamBuffer.match(sentencePattern);

            while (match) {
                const sentence = match[1].trim();
                this.ttsStreamBuffer = this.ttsStreamBuffer.slice(match[1].length);

                if (sentence.length >= 3) {
                    this.queueTtsSentence(sentence);
                }

                match = this.ttsStreamBuffer.match(sentencePattern);
            }
        },
        finishTtsStream() {
            if (this.ttsStreamFallbackTimer) {
                window.clearTimeout(this.ttsStreamFallbackTimer);
            }

            this.ttsStreamFallbackTimer = window.setTimeout(() => {
                if (this.ttsStreamObserver) {
                    this.ttsStreamObserver.disconnect();
                    this.ttsStreamObserver = null;
                }

                const rest = this.ttsStreamBuffer.trim();
                this.ttsStreamBuffer = '';

                if (this.autoRead && rest.length >= 3) {
                    this.queueTtsSentence(rest);
                }
            }, 350);
        },
        queueTtsSentence(text, index = null) {
            const cleanText = String(text || '').trim();
            if (!cleanText) return;

            this.ttsQueue.push({
                text: cleanText,
                index,
                generation: this.ttsCurrentGeneration,
            });

            this.playNextTts();
        },
        async playNextTts() {
            if (this.ttsPlaying || !this.ttsQueue.length) return;

            const item = this.ttsQueue.shift();

            if (!item || item.generation !== this.ttsCurrentGeneration) {
                this.playNextTts();
                return;
            }

            this.ttsPlaying = true;
            this.speaking = true;
            this.speakingIndex = item.index;

            try {
                if (window.MediaSource && MediaSource.isTypeSupported('audio/mpeg')) {
                    await this.playTtsViaMediaSource(item.text);
                } else {
                    await this.playTtsViaBlob(item.text);
                }
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    this.ttsError = this.ttsErrorMessage(error);
                    console.warn('AI-Audioausgabe fehlgeschlagen:', error);
                }
            } finally {
                this.ttsPlaying = false;

                if (this.ttsQueue.length) {
                    this.playNextTts();
                } else {
                    this.speaking = false;
                    this.speakingIndex = null;
                }
            }
        },
        ttsFetchOptions(text) {
            this.ttsAbortController = new AbortController();

            return {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'audio/mpeg',
                    'X-CSRF-TOKEN': this.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: this.ttsAbortController.signal,
                body: JSON.stringify({
                    text,
                    format: 'mp3',
                    speed: Number(this.speechRate || 1),
                }),
            };
        },
        async playTtsViaBlob(text) {
            const response = await fetch(this.ttsEndpoint, this.ttsFetchOptions(text));

            if (!response.ok) {
                throw new Error(await this.ttsResponseError(response));
            }

            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            this.ttsObjectUrls.push(url);

            await this.playAudioUrl(url);
        },
        async playTtsViaMediaSource(text) {
            const mediaSource = new MediaSource();
            const url = URL.createObjectURL(mediaSource);
            this.ttsObjectUrls.push(url);

            const audio = new Audio();
            this.ttsAudio = audio;
            audio.src = url;

            await new Promise((resolve, reject) => {
                let sourceBuffer = null;
                let started = false;
                let finished = false;

                const cleanup = () => {
                    audio.onended = null;
                    audio.onerror = null;
                };

                audio.onended = () => {
                    cleanup();
                    resolve();
                };
                audio.onerror = () => {
                    cleanup();
                    reject(new Error('Audio konnte nicht abgespielt werden.'));
                };

                const appendBuffer = (chunk) => new Promise((appendResolve, appendReject) => {
                    if (!sourceBuffer || mediaSource.readyState !== 'open') {
                        appendResolve();
                        return;
                    }

                    sourceBuffer.addEventListener('updateend', appendResolve, { once: true });
                    sourceBuffer.addEventListener('error', appendReject, { once: true });
                    sourceBuffer.appendBuffer(chunk);
                });

                mediaSource.addEventListener('sourceopen', async () => {
                    try {
                        sourceBuffer = mediaSource.addSourceBuffer('audio/mpeg');
                        const response = await fetch(this.ttsEndpoint, this.ttsFetchOptions(text));

                        if (!response.ok || !response.body) {
                            throw new Error(await this.ttsResponseError(response));
                        }

                        const reader = response.body.getReader();

                        while (true) {
                            const { done, value } = await reader.read();

                            if (done) break;
                            if (!value || value.byteLength === 0) continue;

                            await appendBuffer(value);

                            if (!started) {
                                started = true;
                                await audio.play();
                            }
                        }

                        const endStream = () => {
                            if (finished || mediaSource.readyState !== 'open') return;
                            finished = true;
                            mediaSource.endOfStream();
                        };

                        if (sourceBuffer.updating) {
                            sourceBuffer.addEventListener('updateend', endStream, { once: true });
                        } else {
                            endStream();
                        }

                        if (!started) {
                            await audio.play();
                        }
                    } catch (error) {
                        cleanup();
                        reject(error);
                    }
                }, { once: true });
            });
        },
        playAudioUrl(url) {
            return new Promise((resolve, reject) => {
                const audio = new Audio(url);
                this.ttsAudio = audio;

                audio.onended = () => resolve();
                audio.onerror = () => reject(new Error('Audio konnte nicht abgespielt werden.'));
                audio.play().catch(reject);
            });
        },
        async ttsResponseError(response) {
            const raw = await response.text();

            try {
                const payload = JSON.parse(raw);
                return payload?.detail || payload?.message || `HTTP ${response.status}`;
            } catch {
                return raw || `HTTP ${response.status}`;
            }
        },
        ttsErrorMessage(error) {
            const message = String(error?.message || error || 'Unbekannter Audiofehler.');

            if (message.includes('Failed to fetch')) {
                return `Der Audio-Endpunkt ${this.ttsEndpoint} ist nicht erreichbar.`;
            }

            if (error?.name === 'NotAllowedError') {
                return 'Der Browser hat die Audiowiedergabe blockiert. Bitte den Audio-Test erneut anklicken.';
            }

            return message.length > 400 ? `${message.slice(0, 400)}...` : message;
        },
        stopSpeaking() {
            if (this.ttsStreamObserver) {
                this.ttsStreamObserver.disconnect();
                this.ttsStreamObserver = null;
            }

            if (this.ttsStreamFallbackTimer) {
                window.clearTimeout(this.ttsStreamFallbackTimer);
                this.ttsStreamFallbackTimer = null;
            }

            this.ttsCurrentGeneration++;
            this.ttsQueue = [];
            this.ttsStreamBuffer = '';
            this.ttsStreamLastText = '';

            if (this.ttsAbortController) {
                this.ttsAbortController.abort();
                this.ttsAbortController = null;
            }

            if (this.ttsAudio) {
                this.ttsAudio.pause();
                this.ttsAudio.src = '';
                this.ttsAudio = null;
            }

            this.ttsObjectUrls.forEach((url) => URL.revokeObjectURL(url));
            this.ttsObjectUrls = [];
            this.ttsPlaying = false;
            this.speaking = false;
            this.speakingIndex = null;
        },
        scheduleToolAlerts(events) {
            (Array.isArray(events) ? events : []).forEach((event) => {
                const id = String(event?.id || '');
                if (!id || this.toolAlertTimers[id]) return;

                this.toolAlertTimers[id] = window.setTimeout(async () => {
                    delete this.toolAlertTimers[id];
                    await $wire.dismissToolEvent(id);
                }, 6000);
            });
        },
        dismissToolAlert(id) {
            const key = String(id || '');
            if (!key) return;

            if (this.toolAlertTimers[key]) {
                window.clearTimeout(this.toolAlertTimers[key]);
                delete this.toolAlertTimers[key];
            }

            $wire.dismissToolEvent(key);
        },
        activeScans() {
            const now = Date.now();

            return (Array.isArray(this.scanActivities) ? this.scanActivities : [])
                .filter((scan) => {
                    if (!['queued', 'running', 'stopping'].includes(scan?.status)) return false;

                    const updatedAt = Date.parse(scan?.heartbeat_at || scan?.updated_at || scan?.started_at || '');
                    const maxAge = scan?.status === 'queued' ? 120000 : 35000;

                    return Number.isFinite(updatedAt) && now - updatedAt <= maxAge;
                });
        },
        pausedScans() {
            return (Array.isArray(this.scanActivities) ? this.scanActivities : [])
                .filter((scan) => scan?.status === 'paused');
        },
        async resumePausedScan(scan) {
            const token = String(scan?.token || '');
            if (!token || this.resumingScanToken) return;

            this.resumingScanToken = token;
            this.pendingLabel = `${scan?.label || 'Instagram-Scan'} wird fortgesetzt.`;

            try {
                const result = await $wire.resumeAssistantScan(token);

                if (!result?.ok) {
                    window.alert(result?.message || 'Der Scan konnte nicht fortgesetzt werden.');
                }
            } finally {
                this.resumingScanToken = null;
                this.pendingLabel = '';
                this.scrollMessages();
            }
        },
        scanPercent(scan) {
            return Math.max(0, Math.min(100, Number(scan?.percent || 0)));
        },
        scanStatusLabel(scan) {
            if (scan?.status === 'queued') return 'Warteschlange';
            if (scan?.status === 'stopping') return 'Wird beendet und gespeichert';
            if (scan?.phase) return String(scan.phase).replaceAll('_', ' ');
            return 'Scan laeuft';
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
            if (!this.voiceSupported || this.busy()) return;

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
    x-on:keydown.escape.window="if (showChat) { stopSpeaking(); showChat = false }"
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
    @if(collect($scanActivities)->contains(fn (array $scan): bool => in_array($scan['status'] ?? null, ['queued', 'running', 'stopping'], true)))
        <div wire:poll.2000ms="pollAssistantScans" class="hidden" aria-hidden="true"></div>
    @endif

    @if($status)
        <button
            x-show="!showChat"
            x-cloak
            x-on:click="openChat()"
            class="fixed bottom-5 right-5 z-50 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-600 via-cyan-600 to-emerald-600 text-white shadow-2xl shadow-cyan-900/25 ring-1 ring-white/40 transition hover:-translate-y-0.5 hover:shadow-cyan-700/30"
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
            x-transition:enter="transition-opacity ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition-opacity ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            x-on:click="stopSpeaking(); showChat = false"
            class="fixed inset-0 z-[60] bg-slate-950/55 backdrop-blur-[2px]"
            aria-hidden="true"
        ></div>

        <aside
            x-show="showChat"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-3 scale-95 opacity-0"
            x-transition:enter-end="translate-y-0 scale-100 opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-y-0 scale-100 opacity-100"
            x-transition:leave-end="translate-y-3 scale-95 opacity-0"
            class="fixed bottom-4 right-4 z-[70] flex h-[min(700px,calc(100vh-2rem))] w-[min(440px,calc(100vw-2rem))] flex-col overflow-hidden rounded-[1.75rem] border border-white/80 bg-white shadow-[0_30px_80px_-20px_rgba(15,23,42,.45)] ring-1 ring-slate-900/5"
            role="dialog"
            aria-modal="true"
            aria-label="Investigation Copilot"
        >
            <header class="relative overflow-visible border-b border-cyan-300/40 bg-gradient-to-r from-sky-600 via-cyan-600 to-emerald-600 px-3 py-2.5 text-white">
                <div class="pointer-events-none absolute inset-0 overflow-hidden">
                    <span class="absolute -left-8 -top-12 h-28 w-28 rounded-full bg-white/15 blur-2xl"></span>
                    <span class="absolute -bottom-16 right-12 h-28 w-28 rounded-full bg-emerald-200/25 blur-2xl"></span>
                </div>
                <div class="relative flex items-center justify-between gap-3">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl border border-white/25 bg-white/15 shadow-sm backdrop-blur">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M12 3 4 7v6c0 4.5 3.4 7.4 8 8 4.6-.6 8-3.5 8-8V7l-8-4Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                            <path d="M9 12h6M12 9v6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <div class="flex items-center gap-1.5">
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
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/25 bg-white/10 text-white transition hover:bg-white/20"
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
                                        <p class="mt-1 text-xs leading-5 text-slate-500">Antworten einzeln oder automatisch per AI-TTS-Audiostream vorlesen.</p>
                                    </div>

                                    <div class="flex items-center justify-between gap-4">
                                        <div>
                                            <p class="text-sm font-bold text-slate-800">Automatisch vorlesen</p>
                                            <p class="text-[11px] text-slate-500">Während des Textstreams</p>
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
                                            x-on:click="speak('Die AI-Audioausgabe ist einsatzbereit.')"
                                            x-bind:disabled="!speechSupported"
                                            class="inline-flex flex-1 items-center justify-center rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                                        >
                                            AI-Audio testen
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

                                    <div
                                        x-show="ttsError"
                                        x-cloak
                                        class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs leading-5 text-rose-800"
                                        x-text="ttsError"
                                    ></div>
                                </div>
                            </x-slot>
                        </x-ui.dropdown.anchor-dropdown>

                        <button
                            type="button"
                            wire:click="clearChat"
                            x-on:click="stopSpeaking(); selectedChatOptions = {}"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/25 bg-white/10 text-white transition hover:bg-white/20"
                            title="Chat leeren"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <button
                            type="button"
                            x-on:click="stopSpeaking(); showChat = false"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/25 bg-white/10 text-white transition hover:bg-white/20"
                            title="Schließen"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div x-show="busy()" x-cloak class="absolute inset-x-0 bottom-0 h-0.5 overflow-hidden bg-white/20">
                    <span class="block h-full w-1/2 animate-[pulse_1s_ease-in-out_infinite] rounded-full bg-white shadow-[0_0_12px_rgba(255,255,255,.9)]"></span>
                </div>
            </header>

            <div class="grid min-h-0 flex-1 grid-cols-1">
                <section class="relative flex min-h-0 flex-col">
                    <div class="pointer-events-none absolute inset-x-3 top-3 z-30 space-y-2">
                        <template x-for="event in toolEvents" :key="event.id">
                            <div
                                x-transition:enter="transition ease-out duration-200"
                                x-transition:enter-start="-translate-y-2 opacity-0"
                                x-transition:enter-end="translate-y-0 opacity-100"
                                x-transition:leave="transition ease-in duration-150"
                                x-transition:leave-start="translate-y-0 opacity-100"
                                x-transition:leave-end="-translate-y-2 opacity-0"
                                class="pointer-events-auto rounded-xl border px-3 py-3 shadow-lg backdrop-blur"
                                :class="event.ok
                                    ? 'border-emerald-200 bg-emerald-50/95 text-emerald-950'
                                    : 'border-rose-200 bg-rose-50/95 text-rose-950'"
                                role="status"
                            >
                                <div class="flex items-start gap-3">
                                    <span
                                        class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-black text-white"
                                        :class="event.ok ? 'bg-emerald-600' : 'bg-rose-600'"
                                        x-text="event.ok ? '✓' : '!'"
                                    ></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-3">
                                            <strong class="truncate text-xs" x-text="event.tool"></strong>
                                            <span class="shrink-0 text-[10px] opacity-60" x-text="event.time"></span>
                                        </div>
                                        <p class="mt-1 text-xs leading-5 opacity-80" x-text="event.message"></p>
                                    </div>
                                    <button
                                        type="button"
                                        x-on:click="dismissToolAlert(event.id)"
                                        class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-current opacity-50 transition hover:bg-black/5 hover:opacity-100"
                                        aria-label="Meldung schliessen"
                                    >
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div
                        class="scroll-container min-h-0 flex-1 space-y-3 overflow-y-auto bg-[radial-gradient(circle_at_top_left,_rgba(207,250,254,.65),_transparent_38%),linear-gradient(to_bottom,_#f8fafc,_#f1f5f9)] px-4 py-4"
                        x-ref="messages"
                        x-init="
                            $watch('chatHistory', () => $nextTick(() => $refs.messages.scrollTo({ top: $refs.messages.scrollHeight, behavior: 'smooth' })));
                            $watch('scanActivities', () => $nextTick(() => $refs.messages.scrollTo({ top: $refs.messages.scrollHeight, behavior: 'smooth' })));
                            $watch('submitting', () => $nextTick(() => $refs.messages.scrollTo({ top: $refs.messages.scrollHeight, behavior: 'smooth' })));
                        "
                    >
                        <template x-if="chatHistory.length === 0 && !busy()">
                            <div class="space-y-3.5">
                                <div class="relative overflow-hidden rounded-2xl border border-cyan-100 bg-white/90 p-4 shadow-sm backdrop-blur">
                                    <span class="absolute -right-8 -top-8 h-24 w-24 rounded-full bg-cyan-100/70 blur-2xl"></span>
                                    <div class="relative flex items-start gap-3">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-500 to-emerald-500 text-white shadow-lg shadow-cyan-200/60">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M12 3 4 7v6c0 4.5 3.4 7.4 8 8 4.6-.6 8-3.5 8-8V7l-8-4Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                                <path d="M8.5 12h7M12 8.5v7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                            </svg>
                                        </span>
                                        <div>
                                            <p class="text-sm font-black text-slate-950">Womit soll ich starten?</p>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">Ich berücksichtige die aktuelle Seite, Networkmap und ausgewählte Profile automatisch.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-2.5">
                                    <button
                                        type="button"
                                        @click="quick('Welche Profile sollte ich als naechstes scannen und warum?')"
                                        class="group rounded-2xl border border-sky-200/80 bg-white/90 p-3.5 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-sky-300 hover:bg-sky-50 hover:shadow-md"
                                    >
                                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-100 text-sky-700 transition group-hover:bg-sky-600 group-hover:text-white">
                                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M4 17 9 12l3 3 7-8M15 7h4v4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </span>
                                        <span class="mt-3 block text-xs font-black text-slate-900">Scans priorisieren</span>
                                        <span class="mt-1 block text-[10px] leading-4 text-slate-500">Die sinnvollsten nächsten Profile finden.</span>
                                    </button>

                                    <button
                                        type="button"
                                        @click="quick('Zeige mir meine beobachteten Profile mit Status und schlage die beste Netzwerk-Strategie vor.')"
                                        class="group rounded-2xl border border-violet-200/80 bg-white/90 p-3.5 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-violet-300 hover:bg-violet-50 hover:shadow-md"
                                    >
                                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-100 text-violet-700 transition group-hover:bg-violet-600 group-hover:text-white">
                                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <circle cx="6" cy="7" r="2.5" stroke="currentColor" stroke-width="2"/>
                                                <circle cx="18" cy="6" r="2.5" stroke="currentColor" stroke-width="2"/>
                                                <circle cx="12" cy="18" r="2.5" stroke="currentColor" stroke-width="2"/>
                                                <path d="m8.2 8.2 2.7 7.4M15.7 7.8l-2.6 7.8M8.5 7h7" stroke="currentColor" stroke-width="1.8"/>
                                            </svg>
                                        </span>
                                        <span class="mt-3 block text-xs font-black text-slate-900">Netzwerk-Strategie</span>
                                        <span class="mt-1 block text-[10px] leading-4 text-slate-500">Status und nächste Analyseschritte ordnen.</span>
                                    </button>

                                    <button
                                        type="button"
                                        @click="quick('Welche Profile sollten ins Monitoring und welches Intervall ist sinnvoll?')"
                                        class="group rounded-2xl border border-emerald-200/80 bg-white/90 p-3.5 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-300 hover:bg-emerald-50 hover:shadow-md"
                                    >
                                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 transition group-hover:bg-emerald-600 group-hover:text-white">
                                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M4 13h3l2-6 4 11 2-5h5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </span>
                                        <span class="mt-3 block text-xs font-black text-slate-900">Monitoring bewerten</span>
                                        <span class="mt-1 block text-[10px] leading-4 text-slate-500">Profile und passende Intervalle bestimmen.</span>
                                    </button>

                                    <button
                                        type="button"
                                        @click="quick('Analysiere den gespeicherten Profilgraphen und liste die wichtigsten Kontaktkandidaten fuer den naechsten Scan.')"
                                        class="group rounded-2xl border border-amber-200/80 bg-white/90 p-3.5 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-amber-300 hover:bg-amber-50 hover:shadow-md"
                                    >
                                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-100 text-amber-700 transition group-hover:bg-amber-500 group-hover:text-white">
                                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <circle cx="9" cy="8" r="3" stroke="currentColor" stroke-width="2"/>
                                                <path d="M3.5 19a5.5 5.5 0 0 1 11 0M16 9h5M18.5 6.5v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                            </svg>
                                        </span>
                                        <span class="mt-3 block text-xs font-black text-slate-900">Kontakte finden</span>
                                        <span class="mt-1 block text-[10px] leading-4 text-slate-500">Relevante Kandidaten im Graphen erkennen.</span>
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

                                <div
                                    x-show="item.role === 'assistant' && Array.isArray(item.options) && item.options.length"
                                    class="mt-3 space-y-2 border-t border-slate-100 pt-3"
                                >
                                    <p
                                        x-show="item.options_prompt"
                                        class="text-xs font-bold leading-5 text-slate-600"
                                        x-text="item.options_prompt"
                                    ></p>
                                    <div class="grid gap-2">
                                        <template x-for="(option, optionIndex) in (item.options || [])" :key="'option-' + index + '-' + optionIndex">
                                            <button
                                                type="button"
                                                x-show="selectedChatOptionIndex(item, index) === null || selectedChatOptionIndex(item, index) === optionIndex"
                                                x-on:click="chooseChatOption(item, index, option, optionIndex)"
                                                x-bind:disabled="busy() || selectedChatOptionIndex(item, index) !== null"
                                                x-bind:class="selectedChatOptionIndex(item, index) === optionIndex
                                                    ? 'cursor-default border-emerald-500 bg-emerald-100 shadow-sm'
                                                    : 'border-cyan-200 bg-gradient-to-r from-cyan-50 to-emerald-50/70 hover:-translate-y-0.5 hover:border-cyan-400 hover:shadow-sm disabled:cursor-wait disabled:opacity-50'"
                                                class="group flex w-full items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition"
                                            >
                                                <span
                                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm ring-1 transition"
                                                    x-bind:class="selectedChatOptionIndex(item, index) === optionIndex
                                                        ? 'text-emerald-700 ring-emerald-200'
                                                        : 'text-cyan-700 ring-cyan-100 group-hover:bg-cyan-600 group-hover:text-white'"
                                                >
                                                    <svg x-show="selectedChatOptionIndex(item, index) !== optionIndex" class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                        <path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                    </svg>
                                                    <svg x-show="selectedChatOptionIndex(item, index) === optionIndex" class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                        <path d="m5 12 4 4L19 6" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    </svg>
                                                </span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block text-xs font-black text-slate-900" x-text="option.label"></span>
                                                    <span
                                                        x-show="option.description"
                                                        class="mt-0.5 block text-[11px] leading-4 text-slate-500"
                                                        x-text="option.description"
                                                    ></span>
                                                </span>
                                            </button>
                                        </template>
                                    </div>
                                </div>

                                <div x-show="Array.isArray(item.profiles) && item.profiles.length" class="mt-3 flex flex-wrap gap-2">
                                    <template x-for="profile in (item.profiles || [])" :key="profile.type + '-' + profile.id">
                                        <div class="group/profile relative max-w-full">
                                            <button
                                                type="button"
                                                x-on:click="requestProfileScan(profile)"
                                                x-bind:disabled="busy()"
                                                class="inline-flex max-w-full items-center gap-2 rounded-full border border-slate-200 bg-slate-50 py-1 pl-1 pr-3 text-left transition hover:border-cyan-300 hover:bg-cyan-50 disabled:cursor-wait disabled:opacity-50"
                                                x-bind:title="'Scan für @' + profile.username + ' auswählen'"
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
                                                    <span class="block truncate text-xs font-black text-slate-800 group-hover/profile:text-cyan-800" x-text="profile.display_name || '@' + profile.username"></span>
                                                    <span class="flex items-center gap-1 truncate text-[10px] font-semibold text-cyan-700">
                                                        <svg class="h-3 w-3 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                            <circle cx="11" cy="11" r="6" stroke="currentColor" stroke-width="2"/>
                                                            <path d="m16 16 4 4M11 8v6M8 11h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                                        </svg>
                                                        <span>Scan auswählen</span>
                                                    </span>
                                                </span>
                                            </button>

                                            <div
                                                class="pointer-events-none absolute bottom-[calc(100%-2px)] left-1/2 z-20 flex -translate-x-1/2 translate-y-1 items-center gap-1 rounded-xl border border-slate-200 bg-white p-1.5 opacity-0 shadow-xl transition group-hover/profile:pointer-events-auto group-hover/profile:translate-y-0 group-hover/profile:opacity-100 group-focus-within/profile:pointer-events-auto group-focus-within/profile:translate-y-0 group-focus-within/profile:opacity-100"
                                                aria-label="Direkte Scan-Aktionen"
                                            >
                                                <button
                                                    type="button"
                                                    x-show="profile.type === 'tracked_person'"
                                                    x-on:click.stop="requestProfileScanType(profile, 'mini', 'Mini-Scan')"
                                                    x-bind:disabled="busy()"
                                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-sky-700 transition hover:bg-sky-100 disabled:opacity-40"
                                                    title="Mini-Scan starten"
                                                >
                                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                        <path d="M5 12h14M12 5v14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                                    </svg>
                                                </button>
                                                <button
                                                    type="button"
                                                    x-on:click.stop="requestProfileScanType(profile, 'full', 'Vollanalyse')"
                                                    x-bind:disabled="busy()"
                                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-violet-700 transition hover:bg-violet-100 disabled:opacity-40"
                                                    title="Vollanalyse starten"
                                                >
                                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                        <circle cx="11" cy="11" r="6" stroke="currentColor" stroke-width="2"/>
                                                        <path d="m16 16 4 4M11 8v6M8 11h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                                    </svg>
                                                </button>
                                                <button
                                                    type="button"
                                                    x-show="profile.type === 'tracked_person'"
                                                    x-on:click.stop="requestProfileScanType(profile, 'followers', 'Follower-Scan')"
                                                    x-bind:disabled="busy()"
                                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-emerald-700 transition hover:bg-emerald-100 disabled:opacity-40"
                                                    title="Follower scannen"
                                                >
                                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                        <circle cx="9" cy="8" r="3" stroke="currentColor" stroke-width="2"/>
                                                        <path d="M3.5 19a5.5 5.5 0 0 1 11 0M17 9v6M14 12h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                                    </svg>
                                                </button>
                                                <button
                                                    type="button"
                                                    x-show="profile.type === 'tracked_person'"
                                                    x-on:click.stop="requestProfileScanType(profile, 'suggestions', 'Vorschläge-Scan')"
                                                    x-bind:disabled="busy()"
                                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-amber-700 transition hover:bg-amber-100 disabled:opacity-40"
                                                    title="Vorschläge scannen"
                                                >
                                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                        <path d="M12 3 14.2 8.8 20 11l-5.8 2.2L12 19l-2.2-5.8L4 11l5.8-2.2L12 3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                                    </svg>
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template x-for="scan in activeScans()" :key="'scan-' + scan.token">
                            <div class="max-w-[92%] overflow-hidden rounded-xl border border-cyan-200 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm">
                                <div class="flex items-start gap-3">
                                    <span class="relative mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-cyan-50">
                                        <span class="absolute inset-1 animate-ping rounded-full bg-cyan-300/30"></span>
                                        <svg class="relative h-4 w-4 animate-spin text-cyan-700" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <circle class="opacity-20" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle>
                                            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                                        </svg>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="truncate font-black text-slate-900" x-text="scan.label || 'Instagram-Scan'"></p>
                                                <p class="mt-0.5 text-[11px] font-bold uppercase tracking-wide text-cyan-700" x-text="scanStatusLabel(scan)"></p>
                                            </div>
                                            <span class="shrink-0 text-sm font-black text-cyan-800" x-text="scanPercent(scan) + '%'"></span>
                                        </div>
                                        <p class="mt-2 text-xs leading-5 text-slate-500" x-text="scan.message || 'Scan wird vorbereitet.'"></p>
                                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                            <div
                                                class="h-full rounded-full bg-gradient-to-r from-cyan-500 to-emerald-500 transition-[width] duration-500 ease-out"
                                                :style="`width: ${scanPercent(scan)}%`"
                                            ></div>
                                        </div>
                                        <div
                                            x-show="scan.loaded !== null && scan.loaded !== undefined"
                                            class="mt-1.5 text-right text-[10px] font-semibold text-slate-400"
                                            x-text="scan.expected ? `${scan.loaded} von ${scan.expected}` : `${scan.loaded} geladen`"
                                        ></div>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <template x-for="scan in pausedScans()" :key="'paused-scan-' + scan.token">
                            <div class="max-w-[92%] overflow-hidden rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 shadow-sm">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white text-amber-700 ring-1 ring-amber-200">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="M9 8v8M15 8v8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                                        </svg>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate font-black text-slate-900" x-text="scan.label || 'Instagram-Scan'"></p>
                                        <p class="mt-0.5 text-[11px] font-bold uppercase tracking-wide text-amber-700">Unterbrochen</p>
                                        <p class="mt-2 text-xs leading-5 text-amber-900" x-text="scan.message || 'Der bisherige Datenstand wurde gespeichert.'"></p>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                x-on:click="resumePausedScan(scan)"
                                                x-bind:disabled="busy() || resumingScanToken !== null"
                                                class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-3 py-2 text-xs font-black text-white transition hover:bg-emerald-700 disabled:cursor-wait disabled:opacity-60"
                                            >
                                                <span x-show="resumingScanToken !== scan.token">Scan fortsetzen</span>
                                                <span x-show="resumingScanToken === scan.token">Wird gestartet...</span>
                                            </button>
                                            <button
                                                type="button"
                                                x-on:click="$wire.dismissAssistantScan(scan.token)"
                                                x-bind:disabled="busy()"
                                                class="inline-flex items-center justify-center rounded-lg border border-amber-300 bg-white px-3 py-2 text-xs font-black text-amber-900 transition hover:bg-amber-100 disabled:cursor-wait disabled:opacity-60"
                                            >
                                                Beenden, Daten behalten
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <div
                            x-show="busy() && pendingLabel"
                            x-cloak
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="translate-y-2 opacity-0"
                            x-transition:enter-end="translate-y-0 opacity-100"
                            class="ml-auto max-w-[88%] rounded-2xl rounded-br-md bg-gradient-to-br from-sky-600 to-cyan-700 px-4 py-3 text-sm leading-6 text-white shadow-md shadow-cyan-200/60"
                        >
                            <div class="mb-1 flex items-center justify-between gap-3">
                                <strong class="text-[10px] uppercase tracking-[.14em] text-cyan-100">Du</strong>
                                <span class="inline-flex items-center gap-1.5 text-[10px] text-cyan-100">
                                    Wird gesendet
                                    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-white"></span>
                                </span>
                            </div>
                            <p class="whitespace-pre-line" x-text="pendingLabel"></p>
                        </div>

                        <div
                            x-show="busy()"
                            x-cloak
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="translate-y-2 opacity-0"
                            x-transition:enter-end="translate-y-0 opacity-100"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="translate-y-0 opacity-100"
                            x-transition:leave-end="translate-y-2 opacity-0"
                            class="max-w-[94%] overflow-hidden rounded-2xl border border-cyan-200/80 bg-white/95 px-4 py-3.5 text-sm text-slate-600 shadow-lg shadow-cyan-100/50 backdrop-blur"
                            role="status"
                            aria-live="polite"
                        >
                            <div class="flex items-center gap-3">
                                <span class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-500 to-emerald-500 text-white shadow-md shadow-cyan-200">
                                    <span class="absolute -inset-1 animate-ping rounded-2xl bg-cyan-300/25"></span>
                                    <svg class="relative h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle class="opacity-30" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle>
                                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                                    </svg>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="font-black text-slate-900">Copilot denkt nach</p>
                                        <span class="flex shrink-0 items-center gap-1" aria-hidden="true">
                                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-sky-500 [animation-delay:-.3s]"></span>
                                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-cyan-500 [animation-delay:-.15s]"></span>
                                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-emerald-500"></span>
                                        </span>
                                    </div>
                                    <p
                                        wire:stream="assistant-status-stream"
                                        class="mt-1 text-xs leading-5 text-slate-500"
                                    >Kontext wird geprüft und passende Werkzeuge werden vorbereitet.</p>
                                </div>
                            </div>
                            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full w-full origin-left animate-pulse rounded-full bg-gradient-to-r from-sky-500 via-cyan-400 to-emerald-500"></div>
                            </div>
                            <p
                                wire:stream="assistant-response-stream"
                            x-ref="assistantResponseStream"
                            class="mt-3 whitespace-pre-line border-t border-cyan-100 pt-3 text-sm leading-6 text-slate-700 [&:empty]:hidden"
                            ></p>
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
                            class="overflow-hidden rounded-2xl border bg-white shadow-sm transition focus-within:border-cyan-400 focus-within:ring-4 focus-within:ring-cyan-100"
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
                                x-bind:disabled="busy()"
                                rows="1"
                                class="block min-h-[58px] max-h-36 w-full resize-none border-0 bg-transparent px-4 pb-2 pt-3 text-sm leading-6 text-slate-950 placeholder:text-slate-400 focus:ring-0 disabled:bg-slate-50 disabled:text-slate-400"
                                placeholder="Frage stellen, Profil öffnen oder Analyse starten..."
                            ></textarea>

                            <div class="flex items-center justify-between gap-3 px-2 pb-2">
                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        x-on:click="$refs.fileInput.click()"
                                        :disabled="busy()"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-xl text-slate-500 transition hover:bg-cyan-50 hover:text-cyan-700 disabled:opacity-40"
                                        title="Dateien hinzufügen"
                                    >
                                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="m12.5 6.5-6.8 6.8a3.2 3.2 0 0 0 4.5 4.5l7.6-7.6a4.5 4.5 0 0 0-6.4-6.4L4.6 10.6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        x-on:click="toggleVoice()"
                                        :disabled="!voiceSupported || busy()"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-xl transition disabled:cursor-not-allowed disabled:opacity-30"
                                        :class="listening ? 'bg-rose-100 text-rose-700' : 'text-slate-500 hover:bg-cyan-50 hover:text-cyan-700'"
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
                                        :disabled="busy() || (!(draft || '').trim() && !hasUploads())"
                                        class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-sky-600 via-cyan-600 to-emerald-600 text-white shadow-md shadow-cyan-200 transition hover:-translate-y-0.5 hover:shadow-lg disabled:cursor-not-allowed disabled:from-slate-300 disabled:via-slate-300 disabled:to-slate-300 disabled:shadow-none"
                                        title="Senden"
                                    >
                                        <svg x-show="!busy()" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="m5 12 14-7-4 14-3-6-7-1Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                        </svg>
                                        <svg x-show="busy()" class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
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
