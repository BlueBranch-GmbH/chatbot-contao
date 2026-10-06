/**
 * Chatbot Search Helper Functions
 */

window.ChatbotSearch = window.ChatbotSearch || class ChatbotSearch {
    constructor(config) {
        this.containerId = config.containerId;
        this.query = config.query;
        this.requestToken = config.requestToken;
        this.language = config.language;
        this.pageId = config.pageId;
        this.moduleId = config.moduleId || '';
        this.sig = config.sig || '';
        this.feedback = config.feedback === true;
        this.strings = config.strings || {};
        this.apiUrl = config.apiUrl || '/bluebranch/chatbot/api/v1/generate/stream';

        this.container = document.getElementById(this.containerId);
        if (!this.container) return;

        this.contentDiv = this.container.querySelector('.chatbot-content');
        this.loadingDiv = this.container.querySelector('.chatbot-loading');
        this.sourcesDiv = this.container.querySelector('.chatbot-sources');
        this.sourcesList = this.sourcesDiv ? this.sourcesDiv.querySelector('ul') : null;
        this.feedbackDiv = null;

        this.timerInterval = null;
        this.startTime = null;
        this.isBusy = false;
        this.wasStopped = false;

        // Jede Anfrage bekommt eine Nummer. Ereignisse eines ueberholten Streams
        // erkennen daran, dass sie nicht mehr die aktuelle ist, und halten sich
        // aus der Anzeige heraus.
        this.requestId = 0;
        this.onBusyChange = typeof config.onBusyChange === 'function' ? config.onBusyChange : null;
    }

    init() {
        if (!this.query || this.query.trim() === '') {
            return;
        }

        this.startRequest();
    }

    /**
     * Stellt eine neue Frage im selben Container. Anders als init() ist das fuer
     * wiederholte Aufrufe gedacht: Module ohne Seitenwechsel fragen mehrfach.
     */
    ask(query) {
        if (!query || query.trim() === '') {
            return;
        }

        // Solange eine Antwort laeuft, startet keine zweite: zwei Streams im
        // selben Antwortbereich wuerden sich gegenseitig ueberschreiben.
        if (this.isBusy) {
            return;
        }

        this.query = query;
        this.startRequest();
    }

    /**
     * Raeumt die Anzeige der vorherigen Antwort ab.
     */
    reset() {
        if (this.contentDiv) {
            this.contentDiv.innerHTML = '';
        }
        if (this.sourcesList) {
            this.sourcesList.innerHTML = '';
        }
        if (this.sourcesDiv) {
            this.sourcesDiv.style.display = 'none';
        }
        if (this.feedbackDiv) {
            this.feedbackDiv.remove();
            this.feedbackDiv = null;
        }
    }

    /**
     * Daumen hoch/runter unter der Antwort - nur wenn das Modul es will und der Server eine
     * Kennung fuer diese Antwort geschickt hat.
     */
    renderFeedback(ref) {
        if (!this.feedback || !ref || !this.contentDiv) return;

        this.feedbackDiv = document.createElement('div');
        this.feedbackDiv.className = 'chatbot-feedback-wrap';
        (this.sourcesDiv || this.contentDiv).after(this.feedbackDiv);

        ChatbotFeedback.render(this.feedbackDiv, {
            ref: ref,
            strings: this.strings,
            requestToken: this.requestToken,
        });
    }

    startRequest() {
        this.closeStream();

        const requestId = ++this.requestId;
        this.wasStopped = false;
        this.setBusy(true);

        this.reset();
        this.showLoading(true);
        if (this.loadingDiv) {
            this.loadingDiv.classList.remove('finished');
        }
        this.startTimer();

        ChatbotStreamToken.get(this.requestToken).then((token) => {
            if (requestId !== this.requestId) return;

            this.requestToken = token;
            this.openStream(requestId);
        }).catch((error) => {
            if (requestId !== this.requestId) return;

            this.finishRequest();
            this.handleError(error);
        });
    }

    openStream(requestId) {
        let fullAnswer = '';
        let renderPending = false;
        let ref = null;
        const body = {
            prompt: this.query,
            language: this.language,
            pageId: this.pageId,
        };

        if (this.moduleId) {
            body.moduleId = this.moduleId;
        }

        if (this.sig) {
            body.sig = this.sig;
        }

        // POST statt EventSource: Frage und Token gehoeren nicht in die URL (Access-Logs,
        // Browserverlauf, Referer). Die Route ist je nach Aufruf Frontend oder Backend.
        this.stream = ChatbotStream.open(this.apiUrl, {
            token: this.requestToken,
            body: body,
            handlers: {
                message: (event) => {
                    if (requestId !== this.requestId) return;

                    try {
                        const data = JSON.parse(event.data);

                        if (data.answer) {
                            fullAnswer += data.answer;
                            // Throttle DOM updates to animation frames so the browser
                            // can paint each incremental chunk instead of batching all
                            // events that arrive in the same event-loop tick.
                            if (!renderPending) {
                                renderPending = true;
                                requestAnimationFrame(() => {
                                    renderPending = false;
                                    if (requestId !== this.requestId) return;

                                    this.renderContent(fullAnswer);
                                });
                            }
                        }

                        if (data.sources) {
                            this.renderSources(data.sources);
                        }
                    } catch (e) {
                        console.error("Error parsing SSE data", e);
                    }
                },
                meta: (event) => {
                    if (requestId !== this.requestId) return;

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
                    if (requestId !== this.requestId) return;

                    // Der letzte Chunk kann noch im Animationsframe stecken.
                    if (fullAnswer) {
                        this.renderContent(fullAnswer);
                    }

                    this.finishRequest();
                    this.renderFeedback(ref);
                },
                error: (event) => {
                    if (requestId !== this.requestId) return;

                    this.finishRequest();

                    if (fullAnswer.length > 0) {
                        // If we already have content, treat as finished
                        return;
                    }

                    // Fehlerereignisse der Gegenstelle tragen einen Rumpf mit Begruendung,
                    // echte Verbindungsabbrueche nicht.
                    let meldung = null;

                    if (event && typeof event.data === 'string' && event.data !== '') {
                        try {
                            const data = JSON.parse(event.data);
                            if (data && data.message) {
                                meldung = data.message;
                            }
                        } catch (e) {
                            // Rumpf unlesbar - bei der allgemeinen Meldung bleiben.
                        }
                    }

                    this.handleError(new Error(meldung || 'SSE Connection failed'), meldung);
                },
            },
        });
    }

    /**
     * Bricht die laufende Antwort auf Wunsch des Nutzers ab. Das bereits
     * Gestreamte bleibt stehen -- es ist ja gelesen worden.
     */
    abort() {
        if (!this.isBusy) {
            return;
        }

        // Die Nummer hochzaehlen, damit noch unterwegs befindliche Ereignisse
        // des abgebrochenen Streams nichts mehr in die Anzeige schreiben.
        this.requestId++;
        this.wasStopped = true;
        this.finishRequest();
    }

    /**
     * Beendet den laufenden Stream, stoppt die Uhr und gibt die Eingabe wieder frei.
     */
    finishRequest() {
        this.stopTimer();
        this.closeStream();
        this.setBusy(false);
    }

    closeStream() {
        if (this.stream) {
            this.stream.close();
            this.stream = null;
        }
    }

    /**
     * Meldet den Zustand nach aussen, damit das Modul den Absenden-Knopf sperren kann.
     */
    setBusy(state) {
        this.isBusy = state;

        if (this.onBusyChange) {
            this.onBusyChange(state);
        }
    }

    handleResponse(data) {
        if (data.success && data.answer) {
            this.renderContent(data.answer);
            if (data.sources && data.sources.length > 0) {
                this.renderSources(data.sources);
            }
        } else {
            this.renderError('Entschuldigung, es konnte keine Antwort generiert werden.');
        }
    }

    handleError(error, serverMessage) {
        console.error('Chatbot Error:', error);
        // Eine Begruendung der Gegenstelle (etwa ein erschoepftes Kontingent) sagt mehr
        // als die allgemeine Meldung.
        this.renderError(serverMessage || 'Es ist ein Fehler bei der Anfrage aufgetreten.');
    }

    showLoading(show) {
        if (this.loadingDiv) {
            this.loadingDiv.style.display = show ? 'block' : 'none';
        }
    }

    renderContent(markdown) {
        if (this.contentDiv) {
            this.contentDiv.innerHTML = ChatbotMarkdown.toHtml(markdown);
        }
    }

    renderSources(sources) {
        if (!this.sourcesList || !this.sourcesDiv) return;

        this.sourcesList.innerHTML = '';
        const list = ChatbotSources.list(sources, '', 3);

        // Nur Quellen mit Adresse; Zusatzinhalte ohne Seite erscheinen nicht.
        if (!list) {
            this.sourcesDiv.style.display = 'none';
            return;
        }

        Array.prototype.slice.call(list.children).forEach((li) => this.sourcesList.appendChild(li));
        this.sourcesDiv.style.display = 'block';
    }

    renderError(message) {
        if (this.contentDiv) {
            // Als Text, nicht als HTML: Die Meldung kann von der Gegenstelle stammen.
            const p = document.createElement('p');
            p.className = 'error';
            p.textContent = message;
            this.contentDiv.replaceChildren(p);
        }
    }

    startTimer() {
        // Eine alte Uhr koennte sonst weiterlaufen und die Anzeige der neuen
        // Anfrage ueberschreiben.
        this.clearTimer();

        this.startTime = Date.now();
        this.updateTimerDisplay(0);

        this.timerInterval = setInterval(() => {
            const elapsedTime = Date.now() - this.startTime;
            this.updateTimerDisplay(elapsedTime);
        }, 50);
    }

    stopTimer() {
        this.clearTimer();
        
        // Ensure the final time is shown and stays visible
        if (this.loadingDiv) {
            this.loadingDiv.style.display = 'block';
            this.loadingDiv.classList.add('finished');
            // Update display one last time with 'finished' class
            const elapsedTime = Date.now() - this.startTime;
            this.updateTimerDisplay(elapsedTime);
        }
    }

    clearTimer() {
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
            this.timerInterval = null;
        }
    }

    updateTimerDisplay(ms) {
        const seconds = Math.floor(ms / 1000);
        const milliseconds = Math.floor((ms % 1000) / 10);
        const formattedMs = milliseconds.toString().padStart(2, '0');
        
        const timerText = `${seconds}:${formattedMs} sekunden`;
        
        if (this.loadingDiv) {
            let label = 'Antwort wird generiert...';

            if (this.wasStopped) {
                label = 'Antwort abgebrochen';
            } else if (this.loadingDiv.classList.contains('finished')) {
                label = 'Antwort generiert';
            }

            this.loadingDiv.textContent = `${label} (${timerText})`;
        }
    }
};
