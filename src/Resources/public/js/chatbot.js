/**
 * Chatbot Widget — floating chat button + dialogue window
 */

window.ChatbotWidget = window.ChatbotWidget || class ChatbotWidget {
    constructor(config) {
        this.containerId = config.containerId;
        this.requestToken = config.requestToken;
        this.language = config.language || 'de';
        this.pageId = config.pageId || '';
        this.moduleId = config.moduleId || '';
        this.sig = config.sig || '';
        this.feedback = config.feedback === true;
        this.botName = config.botName || 'Chat';
        this.apiUrl = config.apiUrl || '/bluebranch/chatbot/api/v1/chat/stream';

        this.container = document.getElementById(this.containerId);
        if (!this.container) return;

        this.toggleButton = this.container.querySelector('.chatbot-widget__toggle');
        this.closeButton = this.container.querySelector('.chatbot-widget__close');
        this.clearButton = this.container.querySelector('.chatbot-widget__clear');
        this.fontDecButton = this.container.querySelector('.chatbot-widget__font-btn--dec');
        this.fontIncButton = this.container.querySelector('.chatbot-widget__font-btn--inc');
        this.panel = this.container.querySelector('.chatbot-widget__panel');
        this.messagesEl = this.container.querySelector('.chatbot-widget__messages');
        this.suggestionsEl = this.container.querySelector('.chatbot-widget__suggestions');
        this.form = this.container.querySelector('.chatbot-widget__form');
        this.input = this.container.querySelector('.chatbot-widget__input');
        this.sendButton = this.container.querySelector('.chatbot-widget__send');
        // Das Pfeil-Symbol steht im Template; fuer den Wechsel zurueck aus dem
        // Stopp-Zustand wird es hier festgehalten.
        this.sendIcon = this.sendButton ? this.sendButton.innerHTML : '';
        this.badge = this.container.querySelector('.chatbot-widget__badge');
        this.exportButton = this.container.querySelector('.chatbot-widget__export');
        this.exportMenu = this.container.querySelector('.chatbot-widget__export-menu');

        this.storageKey = 'chatbot_widget_history_' + this.containerId;
        this.history = [];
        this.isOpen = false;
        this.isBusy = false;
        this.abortRequest = null;
        this.hasGreeted = false;
        this.pendingSources = null;
        this.greeting = config.greeting || 'Wie kann ich heute helfen?';
        this.suggestions = Array.isArray(config.suggestions) ? config.suggestions.filter(Boolean) : [];
        this.showSummarize = config.showSummarize !== false;
        this.strings = Object.assign({
            send: 'Nachricht senden',
            stop: 'Antwort stoppen',
            stopped: 'Antwort abgebrochen.',
            summarize: 'Inhalt zusammenfassen',
            summarizePrompt: 'Fasse ausschließlich den folgenden Seiteninhalt kurz und präzise zusammen. Nutze dafür keine anderen Quellen oder Seiten:',
            summarizeFallbackPrompt: 'Bitte fasse den Inhalt dieser Seite kurz zusammen.',
            noAnswer: 'Entschuldigung, es konnte keine Antwort generiert werden.',
            requestError: 'Es ist ein Fehler bei der Anfrage aufgetreten.',
            source: 'Quelle',
            interrupted: 'Antwort unterbrochen.',
            you: 'Sie',
        }, config.strings || {});

        this.fontSizeSteps = [13, 14, 15, 16, 17, 18, 19, 20];
        this.fontStorageKey = 'chatbot_widget_font_size';

        this.loadHistory();
        this.loadFontSize();
    }

    init() {
        if (!this.toggleButton || !this.panel || !this.form || !this.input) return;

        this.toggleButton.addEventListener('click', () => this.toggle());

        if (this.closeButton) {
            this.closeButton.addEventListener('click', () => this.close());
        }

        if (this.clearButton) {
            this.clearButton.addEventListener('click', () => this.clearChat());
        }

        if (this.fontDecButton) {
            this.fontDecButton.addEventListener('click', () => this.adjustFontSize(-1));
        }

        if (this.fontIncButton) {
            this.fontIncButton.addEventListener('click', () => this.adjustFontSize(1));
        }

        this.form.addEventListener('submit', (event) => {
            event.preventDefault();

            // Waehrend der Antwort ist derselbe Knopf der Stopp-Knopf.
            if (this.isBusy) {
                this.stopRequest();
                return;
            }

            this.send();
        });

        this.input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                this.send();
            }
        });

        this.input.addEventListener('input', () => this.autoGrow());

        if (this.exportButton && this.exportMenu) {
            this.exportButton.addEventListener('click', () => this.toggleExportMenu());
            this.exportMenu.querySelectorAll('[data-format]').forEach((item) => {
                item.addEventListener('click', () => {
                    this.toggleExportMenu(false);
                    this.exportChat(item.getAttribute('data-format'));
                });
            });
            document.addEventListener('click', (event) => {
                if (!this.exportMenu.hidden && !this.exportMenu.contains(event.target) && event.target !== this.exportButton) {
                    this.toggleExportMenu(false);
                }
            });
        }

        this.renderSuggestions();
    }

    /**
     * Stellt den Verlauf wieder her - Fragen und Antworten samt Quellen und Bewertung.
     *
     * Eine Antwort, die beim Verlassen der Seite noch lief (`pending`), steht mit dem bis dahin
     * gestreamten Text und einem Hinweis da; fortsetzen laesst sich der Stream nicht.
     */
    loadHistory() {
        let changed = false;

        try {
            const raw = localStorage.getItem(this.storageKey);
            if (!raw) return;

            const stored = JSON.parse(raw);
            if (!Array.isArray(stored)) return;

            // Nach 24 Stunden ohne neue Nachricht beginnt der Chat leer: Auf einem geteilten Rechner
            // soll der naechste Besucher nicht lesen, was der vorige gefragt hat.
            const last = stored.length ? stored[stored.length - 1] : null;
            if (last && typeof last.time === 'number' && Date.now() - last.time > 24 * 3600 * 1000) {
                localStorage.removeItem(this.storageKey);
                return;
            }

            stored.forEach((entry) => {
                if (!entry || (entry.role !== 'user' && entry.role !== 'bot') || typeof entry.content !== 'string') return;

                if (entry.pending) {
                    entry.pending = false;
                    entry.interrupted = true;
                    changed = true;
                }

                this.history.push(entry);
                this.renderEntry(entry);
            });

            if (this.history.length > 0) {
                this.hasGreeted = true;
            }
        } catch (e) {
            // localStorage unavailable (private mode, quota, ...) - just start fresh
        }

        if (changed) {
            this.saveHistory();
        }
    }

    renderEntry(entry) {
        const bubble = this.addMessage(entry.role, entry.content);

        if (entry.role !== 'bot') return;

        if (entry.interrupted) {
            this.appendNote(bubble, this.strings.interrupted);
        }

        if (entry.sources) {
            this.appendSources(bubble, entry.sources);
        }

        if (entry.ref && this.feedback) {
            this.appendFeedback(bubble, entry);
        }
    }

    saveHistory() {
        try {
            localStorage.setItem(this.storageKey, JSON.stringify(this.history.slice(-50)));
        } catch (e) {
            // ignore - not essential to the chat working
        }
    }

    loadFontSize() {
        let size = 15;

        try {
            const stored = parseInt(localStorage.getItem(this.fontStorageKey), 10);
            if (this.fontSizeSteps.includes(stored)) {
                size = stored;
            }
        } catch (e) {
            // ignore - fall back to the default size
        }

        this.applyFontSize(size);
    }

    applyFontSize(size) {
        this.fontSize = size;
        this.container.style.setProperty('--chatbot-widget-message-font-size', size + 'px');
    }

    adjustFontSize(direction) {
        const index = this.fontSizeSteps.indexOf(this.fontSize);
        const nextIndex = Math.min(this.fontSizeSteps.length - 1, Math.max(0, index + direction));
        const nextSize = this.fontSizeSteps[nextIndex];

        this.applyFontSize(nextSize);

        try {
            localStorage.setItem(this.fontStorageKey, String(nextSize));
        } catch (e) {
            // ignore - the size just won't persist across page loads
        }
    }

    clearChat() {
        this.toggleExportMenu(false);
        this.history = [];
        this.hasGreeted = false;
        this.pendingSources = null;
        this.messagesEl.innerHTML = '';

        try {
            localStorage.removeItem(this.storageKey);
        } catch (e) {
            // ignore
        }

        if (this.isOpen) {
            this.maybeGreet();
        }
    }

    toggle() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        this.isOpen = true;
        this.panel.hidden = false;

        // Force a reflow so the browser registers the un-hidden state
        // before the 'is-open' class change is animated.
        void this.panel.offsetWidth;

        requestAnimationFrame(() => {
            this.panel.classList.add('is-open');
        });

        this.toggleButton.setAttribute('aria-expanded', 'true');
        this.toggleButton.classList.add('is-active');
        this.toggleButton.hidden = true;
        this.setUnread(false);
        this.input.focus();
        this.scrollToBottom();
        this.maybeGreet();
    }

    maybeGreet() {
        if (this.hasGreeted || this.history.length > 0) return;
        this.hasGreeted = true;

        const typingRow = this.addTyping();

        setTimeout(() => {
            typingRow.remove();
            this.addMessage('bot', this.greeting);
            this.history.push({ role: 'bot', content: this.greeting, time: Date.now() });
            this.saveHistory();
        }, 800);
    }

    renderSuggestions() {
        if (!this.suggestionsEl) return;

        this.suggestionsEl.innerHTML = '';

        const addPill = (text, onClick) => {
            const pill = document.createElement('button');
            pill.type = 'button';
            pill.className = 'chatbot-widget__suggestion';
            pill.textContent = text;
            pill.addEventListener('click', onClick);
            this.suggestionsEl.appendChild(pill);
        };

        this.suggestions.forEach((text) => {
            addPill(text, () => this.sendPrompt(text));
        });

        if (this.showSummarize) {
            addPill(this.strings.summarize, () => this.summarizePage());
        }

        this.suggestionsEl.hidden = false;
    }

    close() {
        this.isOpen = false;
        this.panel.classList.remove('is-open');
        this.toggleButton.setAttribute('aria-expanded', 'false');
        this.toggleButton.classList.remove('is-active');
        this.toggleButton.hidden = false;

        const hide = () => {
            this.panel.hidden = true;
        };

        this.panel.addEventListener('transitionend', hide, { once: true });
        // Fallback in case the transition never fires (e.g. reduced motion).
        setTimeout(hide, 250);
    }

    setUnread(state) {
        if (this.badge) {
            this.badge.hidden = !state;
        }
    }

    autoGrow() {
        this.input.style.height = 'auto';
        this.input.style.height = Math.min(this.input.scrollHeight, 120) + 'px';
    }

    /**
     * Sperrt Eingabe und Vorschlaege, solange eine Antwort laeuft, und macht aus
     * dem Senden- einen Stopp-Knopf. Ohne das schickt ein zweiter Klick eine
     * zweite Anfrage los, deren Stream in dieselbe Sprechblase schreibt.
     */
    setBusy(state) {
        this.isBusy = state;
        this.input.disabled = state;

        if (this.sendButton) {
            this.sendButton.innerHTML = state ? '&#9632;' : this.sendIcon;
            this.sendButton.classList.toggle('chatbot-widget__send--stop', state);
            this.sendButton.setAttribute('aria-label', state ? this.strings.stop : this.strings.send);
            this.sendButton.setAttribute('title', state ? this.strings.stop : this.strings.send);
        }

        // Ein Leeren mitten im Stream wuerde die Sprechblase entfernen, in die
        // gerade geschrieben wird.
        if (this.clearButton) {
            this.clearButton.disabled = state;
        }

        if (this.suggestionsEl) {
            this.suggestionsEl.querySelectorAll('.chatbot-widget__suggestion').forEach((pill) => {
                pill.disabled = state;
            });
        }

        this.form.setAttribute('aria-busy', state ? 'true' : 'false');
    }

    /**
     * Bricht die laufende Antwort ab. Was schon gestreamt wurde, bleibt stehen --
     * es ist ja gelesen worden.
     */
    stopRequest() {
        if (this.abortRequest) {
            this.abortRequest();
        }
    }

    send() {
        const text = this.input.value.trim();
        if (!text || this.isBusy) return;

        this.input.value = '';
        this.autoGrow();

        this.sendPrompt(text);
    }

    sendPrompt(text) {
        if (!text || this.isBusy) return;

        this.addMessage('user', text);
        this.history.push({ role: 'user', content: text, time: Date.now() });
        this.saveHistory();

        this.requestAnswer(text);
    }

    summarizePage() {
        if (this.isBusy) return;

        const displayText = this.strings.summarize;
        const pageContent = this.extractPageContent();
        const prompt = pageContent
            ? this.strings.summarizePrompt + '\n\n---\n' + pageContent + '\n---'
            : this.strings.summarizeFallbackPrompt;

        this.addMessage('user', displayText);
        this.history.push({ role: 'user', content: displayText, time: Date.now() });
        this.saveHistory();

        // Gespeichert wird statt des langen Seitentexts nur ein Platzhalter.
        this.requestAnswer(prompt, { includeContext: false, summarize: true });
    }

    extractPageContent() {
        const source = document.querySelector('main') || document.body;
        if (!source) return '';

        const clone = source.cloneNode(true);
        clone.querySelectorAll('script, style, noscript, .chatbot-widget, nav, header, footer').forEach((el) => el.remove());

        const text = (clone.textContent || '').replace(/\s+/g, ' ').trim();
        // Keep this well under typical server/proxy request-line limits (~8KB) —
        // the text is sent as a GET query param and non-ASCII chars percent-encode
        // to multiple bytes each.
        const maxLength = 2500;

        return text.length > maxLength ? text.slice(0, maxLength) + ' …' : text;
    }

    addMessage(role, text) {
        const row = document.createElement('div');
        row.className = 'chatbot-widget__message chatbot-widget__message--' + role;

        const bubble = document.createElement('div');
        bubble.className = 'chatbot-widget__bubble';

        if (role === 'bot') {
            bubble.innerHTML = ChatbotMarkdown.toHtml(text);
        } else {
            bubble.textContent = text;
        }

        row.appendChild(bubble);
        this.messagesEl.appendChild(row);
        this.scrollToBottom();

        return bubble;
    }

    addTyping() {
        const row = document.createElement('div');
        row.className = 'chatbot-widget__message chatbot-widget__message--bot chatbot-widget__message--typing';
        row.innerHTML = '<div class="chatbot-widget__bubble chatbot-widget__typing"><span></span><span></span><span></span></div>';
        this.messagesEl.appendChild(row);
        this.scrollToBottom();

        return row;
    }

    buildChatContext() {
        return this.history
            .slice(-10)
            .map((entry) => (entry.role === 'user' ? 'Nutzer: ' : 'Assistent: ') + entry.content)
            .join('\n');
    }

    requestAnswer(prompt, options) {
        const includeContext = !options || options.includeContext !== false;
        const summarize = !!(options && options.summarize);

        this.setBusy(true);

        const typingRow = this.addTyping();
        let bubble = null;
        let fullAnswer = '';
        let renderPending = false;
        let entry = null;
        let saveTimer = null;
        let ref = null;
        this.pendingSources = null;

        // Den Verlauf beim Absenden festhalten, nicht erst wenn der Token eingetroffen ist.
        const chatContext = includeContext ? this.buildChatContext() : '';
        let stream = null;
        let aborted = false;

        /*
         * Die Antwort steht schon waehrend des Streams im Verlauf (`pending`) und wird
         * gedrosselt gesichert. Wer mitten in der Antwort die Seite wechselt, findet sie danach
         * bis zu diesem Punkt wieder - vorher ging sie ganz verloren.
         */
        const scheduleSave = () => {
            if (saveTimer) return;
            saveTimer = setTimeout(() => {
                saveTimer = null;
                this.saveHistory();
            }, 400);
        };

        const finish = () => {
            this.abortRequest = null;
            if (stream) {
                stream.close();
            }
            if (saveTimer) {
                clearTimeout(saveTimer);
                saveTimer = null;
            }
            this.setBusy(false);
            this.input.focus();

            if (entry) {
                entry.content = fullAnswer;
                entry.pending = false;
                // Ende der Antwort - fuer die Anzeigedauer im Untertitel-Export.
                entry.end = Date.now();
                this.saveHistory();

                if (!this.isOpen) {
                    this.setUnread(true);
                }
            }
        };

        this.abortRequest = () => {
            aborted = true;

            // Kam noch gar nichts an, bleibt sonst nur die Frage ohne Antwort stehen.
            if (!bubble) {
                typingRow.remove();
                this.addMessage('bot', this.strings.stopped);
            }

            finish();
        };

        const handlers = {
            message: (event) => {
                try {
                    const data = JSON.parse(event.data);

                    if (data.answer) {
                        if (!bubble) {
                            typingRow.remove();
                            bubble = this.addMessage('bot', '');
                            entry = { role: 'bot', content: '', time: Date.now(), pending: true };
                            this.history.push(entry);
                        }

                        fullAnswer += data.answer;
                        entry.content = fullAnswer;
                        scheduleSave();

                        if (!renderPending) {
                            renderPending = true;
                            requestAnimationFrame(() => {
                                bubble.innerHTML = ChatbotMarkdown.toHtml(fullAnswer);
                                this.scrollToBottom();
                                renderPending = false;
                            });
                        }
                    }

                    if (data.sources && data.sources.length > 0) {
                        this.pendingSources = ChatbotSources.linkable(data.sources)
                            .slice(0, 3)
                            .map((source) => ({ title: source.title || '', url: source.url }));
                    }
                } catch (e) {
                    console.error('Error parsing SSE data', e);
                }
            },
            meta: (event) => {
                try {
                    const data = JSON.parse(event.data);
                    if (data && typeof data.ref === 'string' && /^[a-f0-9]{32}$/.test(data.ref)) {
                        ref = data.ref;
                    }
                } catch (e) {
                    // Ohne Kennung gibt es eben kein Feedback.
                }
            },
            end: () => {
                if (!bubble) {
                    typingRow.remove();
                    this.addMessage('bot', this.strings.noAnswer);
                } else {
                    // Der letzte Chunk kann noch im Animationsframe stecken.
                    bubble.innerHTML = ChatbotMarkdown.toHtml(fullAnswer);

                    if (this.pendingSources && this.pendingSources.length > 0) {
                        entry.sources = this.pendingSources;
                        this.appendSources(bubble, this.pendingSources);
                    }

                    if (ref) {
                        entry.ref = ref;
                        if (this.feedback) {
                            this.appendFeedback(bubble, entry);
                        }
                    }
                }
                this.pendingSources = null;
                finish();
            },
            error: (event) => {
                if (!bubble) {
                    typingRow.remove();
                    // Fehlerereignisse der Gegenstelle tragen einen Rumpf, echte Verbindungsabbrueche
                    // nicht. Wo die Gegenstelle einen Grund mitschickt - etwa ein erschoepftes
                    // Kontingent -, wird er angezeigt statt der allgemeinen Meldung.
                    this.addMessage('bot', this.messageFromError(event));
                }
                finish();
            },
        };

        ChatbotStreamToken.get(this.requestToken).then((token) => {
            if (aborted) return;

            this.requestToken = token;
            stream = this.openStream(prompt, chatContext, handlers, summarize);
        }).catch((error) => {
            if (aborted) return;

            console.error('Chatbot token request failed', error);
            typingRow.remove();
            this.addMessage('bot', this.strings.requestError);
            finish();
        });
    }

    /**
     * POST statt EventSource: Frage, Chatverlauf und Token gehoeren nicht in die URL
     * (Access-Logs, Browserverlauf, Referer).
     */
    openStream(prompt, chatContext, handlers, summarize) {
        const body = {
            prompt: prompt,
            language: this.language,
        };

        if (this.pageId) {
            body.pageId = this.pageId;
        }

        if (this.moduleId) {
            body.moduleId = this.moduleId;
        }

        if (this.sig) {
            body.sig = this.sig;
        }

        if (summarize) {
            body.summarize = true;
        }

        if (chatContext) {
            body.chat_context = chatContext;
        }

        return ChatbotStream.open(this.apiUrl, {
            token: this.requestToken,
            body: body,
            handlers: handlers,
        });
    }

    /**
     * Zieht die Begruendung aus einem SSE-Fehlerereignis, sonst die allgemeine Meldung.
     */
    messageFromError(event) {
        if (!event || typeof event.data !== 'string' || event.data === '') {
            return this.strings.requestError;
        }

        try {
            const data = JSON.parse(event.data);
            return data && data.message ? data.message : this.strings.requestError;
        } catch (e) {
            return this.strings.requestError;
        }
    }

    appendSources(bubble, sources) {
        const list = ChatbotSources.list(sources, 'chatbot-widget__sources', 3);

        if (list) {
            bubble.appendChild(list);
        }
    }

    appendNote(bubble, text) {
        const note = document.createElement('p');
        note.className = 'chatbot-widget__note';
        note.textContent = text;
        bubble.appendChild(note);
    }

    appendFeedback(bubble, entry) {
        ChatbotFeedback.render(bubble, {
            ref: entry.ref,
            rating: entry.rating || '',
            strings: this.strings,
            requestToken: this.requestToken,
            onChange: (rating) => {
                entry.rating = rating;
                this.saveHistory();
            },
        });
    }

    toggleExportMenu(state) {
        if (!this.exportMenu || !this.exportButton) return;

        const open = typeof state === 'boolean' ? state : this.exportMenu.hidden;
        this.exportMenu.hidden = !open;
        this.exportButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    /**
     * Laedt den Verlauf als Text- oder WebVTT-Datei herunter - im Browser erzeugt, ohne
     * Serveraufruf.
     */
    exportChat(format) {
        const entries = this.history.filter((entry) => entry && typeof entry.content === 'string' && entry.content !== '');

        if (entries.length === 0) return;

        const speaker = (entry) => (entry.role === 'user' ? this.strings.you : this.botName);
        const vtt = format === 'vtt';
        const content = vtt ? ChatbotExport.vtt(entries, speaker) : ChatbotExport.txt(entries, speaker, this.botName);
        const date = new Date().toISOString().slice(0, 10);

        ChatbotExport.download('chat-' + date + (vtt ? '.vtt' : '.txt'), content, vtt ? 'text/vtt' : 'text/plain');
    }

    scrollToBottom() {
        this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
    }
};
