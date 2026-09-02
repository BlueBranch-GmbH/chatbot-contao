/**
 * Chatbot Search Helper Functions
 */

class ChatbotSearch {
    constructor(config) {
        this.containerId = config.containerId;
        this.query = config.query;
        this.requestToken = config.requestToken;
        this.language = config.language;
        this.pageId = config.pageId;
        this.apiUrl = config.apiUrl || '/bluebranch/chatbot/api/v1/generate/stream';

        this.container = document.getElementById(this.containerId);
        if (!this.container) return;

        this.contentDiv = this.container.querySelector('.chatbot-content');
        this.loadingDiv = this.container.querySelector('.chatbot-loading');
        this.sourcesDiv = this.container.querySelector('.chatbot-sources');
        this.sourcesList = this.sourcesDiv ? this.sourcesDiv.querySelector('ul') : null;

        this.timerInterval = null;
        this.startTime = null;
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
    }

    startRequest() {
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }

        this.reset();
        this.showLoading(true);
        if (this.loadingDiv) {
            this.loadingDiv.classList.remove('finished');
        }
        this.startTimer();

        // Use the configured apiUrl (might be backend or frontend scoped)
        const url = new URL(this.apiUrl, window.location.origin);
        url.searchParams.append('prompt', this.query);
        url.searchParams.append('language', this.language);
        url.searchParams.append('pageId', this.pageId);
        url.searchParams.append('token', this.requestToken);

        const eventSource = new EventSource(url.toString());
        this.eventSource = eventSource;
        let fullAnswer = '';
        let renderPending = false;

        eventSource.onmessage = (event) => {
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
                            this.renderContent(fullAnswer);
                            renderPending = false;
                        });
                    }
                }

                if (data.sources) {
                    this.renderSources(data.sources);
                }
            } catch (e) {
                console.error("Error parsing SSE data", e);
            }
        };

        eventSource.addEventListener('end', (event) => {
            this.stopTimer();
            eventSource.close();
        });

        eventSource.addEventListener('error', (event) => {
            this.stopTimer();
            eventSource.close();

            if (fullAnswer.length > 0) {
                // If we already have content, treat as finished
            } else {
                // Named SSE-Fehlerereignisse tragen einen Rumpf mit Begruendung, echte
                // Verbindungsabbrueche nicht.
                let meldung = 'SSE Connection failed';

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

                this.handleError(new Error(meldung));
            }
        });

        // Wir brauchen einen Weg um das Ende des Streams zu erkennen, 
        // falls die API kein spezielles "end" Event sendet.
        // Die meisten SSE Implementierungen schließen den Stream wenn fertig.
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

    handleError(error) {
        console.error('Chatbot Error:', error);
        this.renderError('Es ist ein Fehler bei der Anfrage aufgetreten.');
    }

    showLoading(show) {
        if (this.loadingDiv) {
            this.loadingDiv.style.display = show ? 'block' : 'none';
        }
    }

    renderContent(markdown) {
        if (this.contentDiv && typeof marked !== 'undefined') {
            this.contentDiv.innerHTML = marked.parse(markdown);
        }
    }

    renderSources(sources) {
        if (!this.sourcesList || !this.sourcesDiv) return;

        this.sourcesList.innerHTML = '';
        const sourcesToShow = sources.slice(0, 3);

        sourcesToShow.forEach(source => {
            const li = document.createElement('li');
            if (source.url) {
                const a = document.createElement('a');
                a.href = source.url;
                a.target = '_blank';
                a.textContent = source.title || source.url;
                li.appendChild(a);
            } else {
                li.textContent = source.title || 'Quelle';
            }
            this.sourcesList.appendChild(li);
        });

        this.sourcesDiv.style.display = 'block';
    }

    renderError(message) {
        if (this.contentDiv) {
            this.contentDiv.innerHTML = `<p class="error">${message}</p>`;
        }
    }

    startTimer() {
        this.startTime = Date.now();
        this.updateTimerDisplay(0);

        this.timerInterval = setInterval(() => {
            const elapsedTime = Date.now() - this.startTime;
            this.updateTimerDisplay(elapsedTime);
        }, 50);
    }

    stopTimer() {
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
        }
        
        // Ensure the final time is shown and stays visible
        if (this.loadingDiv) {
            this.loadingDiv.style.display = 'block';
            this.loadingDiv.classList.add('finished');
            // Update display one last time with 'finished' class
            const elapsedTime = Date.now() - this.startTime;
            this.updateTimerDisplay(elapsedTime);
        }
    }

    updateTimerDisplay(ms) {
        const seconds = Math.floor(ms / 1000);
        const milliseconds = Math.floor((ms % 1000) / 10);
        const formattedMs = milliseconds.toString().padStart(2, '0');
        
        const timerText = `${seconds}:${formattedMs} sekunden`;
        
        if (this.loadingDiv) {
            const label = this.loadingDiv.classList.contains('finished') ? 'Antwort generiert' : 'Antwort wird generiert...';
            this.loadingDiv.textContent = `${label} (${timerText})`;
        }
    }
}
