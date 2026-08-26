/**
 * Chatbot Widget — floating chat button + dialogue window
 */

class ChatbotWidget {
    constructor(config) {
        this.containerId = config.containerId;
        this.requestToken = config.requestToken;
        this.language = config.language || 'de';
        this.pageId = config.pageId || '';
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
        this.badge = this.container.querySelector('.chatbot-widget__badge');

        this.storageKey = 'chatbot_widget_history_' + this.containerId;
        this.history = [];
        this.isOpen = false;
        this.isBusy = false;
        this.hasGreeted = false;
        this.pendingSources = null;
        this.greeting = config.greeting || 'Wie kann ich heute helfen?';
        this.suggestions = Array.isArray(config.suggestions) ? config.suggestions.filter(Boolean) : [];
        this.showSummarize = config.showSummarize !== false;
        this.strings = Object.assign({
            summarize: 'Inhalt zusammenfassen',
            summarizePrompt: 'Fasse ausschließlich den folgenden Seiteninhalt kurz und präzise zusammen. Nutze dafür keine anderen Quellen oder Seiten:',
            summarizeFallbackPrompt: 'Bitte fasse den Inhalt dieser Seite kurz zusammen.',
            noAnswer: 'Entschuldigung, es konnte keine Antwort generiert werden.',
            requestError: 'Es ist ein Fehler bei der Anfrage aufgetreten.',
            source: 'Quelle',
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
            this.send();
        });

        this.input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                this.send();
            }
        });

        this.input.addEventListener('input', () => this.autoGrow());

        this.renderSuggestions();
    }

    loadHistory() {
        try {
            const raw = localStorage.getItem(this.storageKey);
            if (!raw) return;

            const stored = JSON.parse(raw);
            if (!Array.isArray(stored)) return;

            stored.forEach((entry) => {
                if (!entry || (entry.role !== 'user' && entry.role !== 'bot') || typeof entry.content !== 'string') return;
                this.history.push(entry);
                this.addMessage(entry.role, entry.content);
            });

            if (this.history.length > 0) {
                this.hasGreeted = true;
            }
        } catch (e) {
            // localStorage unavailable (private mode, quota, ...) - just start fresh
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
            this.history.push({ role: 'bot', content: this.greeting });
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
        this.history.push({ role: 'user', content: text });
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
        this.history.push({ role: 'user', content: displayText });
        this.saveHistory();

        this.requestAnswer(prompt, { includeContext: false });
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

        if (role === 'bot' && typeof marked !== 'undefined') {
            bubble.innerHTML = marked.parse(text);
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

        this.isBusy = true;
        this.input.disabled = true;

        const typingRow = this.addTyping();
        let bubble = null;
        let fullAnswer = '';
        let renderPending = false;
        this.pendingSources = null;

        const url = new URL(this.apiUrl, window.location.origin);
        url.searchParams.set('prompt', prompt);
        url.searchParams.set('language', this.language);
        url.searchParams.set('token', this.requestToken);

        if (this.pageId) {
            url.searchParams.set('pageId', this.pageId);
        }

        const chatContext = includeContext ? this.buildChatContext() : '';
        if (chatContext) {
            url.searchParams.set('chat_context', chatContext);
        }

        const eventSource = new EventSource(url.toString());

        const finish = () => {
            eventSource.close();
            this.isBusy = false;
            this.input.disabled = false;
            this.input.focus();

            if (fullAnswer) {
                this.history.push({ role: 'bot', content: fullAnswer });
                if (!this.isOpen) {
                    this.setUnread(true);
                }
            }
        };

        eventSource.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);

                if (data.answer) {
                    if (!bubble) {
                        typingRow.remove();
                        bubble = this.addMessage('bot', '');
                    }

                    fullAnswer += data.answer;

                    if (!renderPending) {
                        renderPending = true;
                        requestAnimationFrame(() => {
                            bubble.innerHTML = typeof marked !== 'undefined' ? marked.parse(fullAnswer) : fullAnswer;
                            this.scrollToBottom();
                            renderPending = false;
                        });
                    }
                }

                if (data.sources && data.sources.length > 0) {
                    this.pendingSources = data.sources;
                }
            } catch (e) {
                console.error('Error parsing SSE data', e);
            }
        };

        eventSource.addEventListener('end', () => {
            if (!bubble) {
                typingRow.remove();
                this.addMessage('bot', this.strings.noAnswer);
            } else if (this.pendingSources) {
                this.appendSources(bubble, this.pendingSources);
                this.pendingSources = null;
            }
            finish();
        });

        eventSource.addEventListener('error', () => {
            if (!bubble) {
                typingRow.remove();
                this.addMessage('bot', this.strings.requestError);
            }
            finish();
        });
    }

    appendSources(bubble, sources) {
        const list = document.createElement('ul');
        list.className = 'chatbot-widget__sources';

        sources.slice(0, 3).forEach((source) => {
            const li = document.createElement('li');

            if (source.url) {
                const a = document.createElement('a');
                a.href = source.url;
                a.target = '_blank';
                a.rel = 'noopener';
                a.textContent = source.title || source.url;
                li.appendChild(a);
            } else {
                li.textContent = source.title || this.strings.source;
            }

            list.appendChild(li);
        });

        bubble.appendChild(list);
    }

    scrollToBottom() {
        this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
    }
}
