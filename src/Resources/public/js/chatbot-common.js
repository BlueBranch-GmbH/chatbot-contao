/**
 * Gemeinsame Helfer aller Chatbot-Module: Token, Antwort-Stream und sichere Darstellung.
 *
 * Die Datei darf mehrfach eingebunden sein (mehrere Module auf einer Seite); jeder Helfer
 * wird nur einmal angelegt.
 */

/**
 * Holt den Sitzungs-Token fuer die Antwort-Routen erst, wenn wirklich gefragt wird.
 *
 * Stuende der Token im Seiten-HTML, muesste jede Seite mit Chatbot eine Session anlegen -
 * und Contao liefert Antworten mit Session-Cookie nie aus dem HTTP-Cache. So bekommt nur,
 * wer den Chatbot tatsaechlich benutzt, eine Session.
 */
window.ChatbotStreamToken = window.ChatbotStreamToken || (function () {
    let pending = null;

    return {
        /**
         * @param {string} [preset] Bereits bekannter Token (z. B. im Backend) - dann entfaellt die Anfrage.
         * @returns {Promise<string>}
         */
        get(preset) {
            if (preset) {
                return Promise.resolve(preset);
            }

            if (!pending) {
                pending = fetch('/bluebranch/chatbot/api/v1/token', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                })
                    .then((response) => {
                        if (!response.ok) {
                            throw new Error('Token request failed (' + response.status + ')');
                        }
                        return response.json();
                    })
                    .then((data) => {
                        if (!data || !data.token) {
                            throw new Error('Token missing in response');
                        }
                        return data.token;
                    })
                    .catch((error) => {
                        // Beim naechsten Versuch neu anfragen statt den Fehler festzuhalten.
                        pending = null;
                        throw error;
                    });
            }

            return pending;
        },
    };
})();

/**
 * Liest eine Server-Sent-Events-Antwort ueber fetch statt ueber EventSource.
 *
 * EventSource kennt nur GET: Frage, Chatverlauf und Token stuenden in der URL und damit in
 * jedem Access-Log, im Browserverlauf und im Referer. Hier gehen sie im Rumpf einer
 * POST-Anfrage, der Token im Header.
 *
 * Die Handler bekommen wie bei EventSource ein Objekt mit `data` (Rohtext):
 * `message` fuer normale Ereignisse, `end` am regulaeren Ende, `error` bei einem Fehler -
 * mit dem Rumpf des Fehlerereignisses, sonst ohne `data` (Abbruch der Verbindung).
 */
window.ChatbotStream = window.ChatbotStream || {
    /**
     * @returns {{close: function(): void}}
     */
    open(url, options) {
        const controller = new AbortController();
        const handlers = options.handlers || {};
        let closed = false;
        let finished = false;

        const emit = (type, data) => {
            if (closed || finished) return;
            if (type === 'end' || type === 'error') {
                finished = true;
            }
            if (typeof handlers[type] === 'function') {
                handlers[type](data === undefined ? {} : { data: data });
            }
        };

        const dispatchBlock = (block) => {
            let type = 'message';
            const data = [];

            block.split(/\r?\n/).forEach((line) => {
                if (line.startsWith('event:')) {
                    type = line.slice(6).trim();
                } else if (line.startsWith('data:')) {
                    data.push(line.slice(5).replace(/^ /, ''));
                }
            });

            if (data.length > 0 || type !== 'message') {
                emit(type, data.join('\n'));
            }
        };

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            signal: controller.signal,
            headers: {
                'Accept': 'text/event-stream',
                'Content-Type': 'application/json',
                'X-CSRF-Token': options.token || '',
            },
            body: JSON.stringify(options.body || {}),
        }).then((response) => {
            if (!response.ok || !response.body) {
                // Auch abgelehnte Anfragen tragen meist ein Fehlerereignis mit Begruendung.
                return response.text().then((text) => {
                    const match = /^data:\s?(.*)$/m.exec(text || '');
                    emit('error', match ? match[1] : undefined);
                });
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            const pump = () => reader.read().then(({ done, value }) => {
                if (done) {
                    if (buffer.trim() !== '') {
                        dispatchBlock(buffer);
                    }
                    // Ohne "end"-Ereignis ist die Verbindung abgerissen.
                    emit('error');
                    return;
                }

                buffer += decoder.decode(value, { stream: true });

                let index;
                while ((index = buffer.search(/\r?\n\r?\n/)) !== -1) {
                    const block = buffer.slice(0, index);
                    buffer = buffer.slice(index).replace(/^\r?\n\r?\n/, '');
                    dispatchBlock(block);
                }

                if (!closed && !finished) {
                    return pump();
                }

                return reader.cancel().catch(() => {});
            });

            return pump();
        }).catch(() => {
            // Ein Abbruch durch close() ist gewollt und kein Fehler.
            emit('error');
        });

        return {
            close() {
                closed = true;
                controller.abort();
            },
        };
    },
};

/**
 * Wandelt die Markdown-Antwort der KI in HTML um und laesst dabei nur harmlose Elemente
 * stehen.
 *
 * Die Antwort stammt von einem Sprachmodell, das auch Text aus Frage und indexierten
 * Seiten wiedergibt. Ohne Filter koennte darueber HTML mit Skript-Attributen oder
 * `javascript:`-Links in die Seite gelangen.
 */
window.ChatbotMarkdown = window.ChatbotMarkdown || (function () {
    const ALLOWED_TAGS = new Set([
        'P', 'BR', 'HR', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'DEL', 'CODE', 'PRE', 'BLOCKQUOTE',
        'UL', 'OL', 'LI', 'A', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
        'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD', 'SPAN', 'SUP', 'SUB',
    ]);
    const DROP_WITH_CONTENT = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'TEMPLATE', 'NOSCRIPT', 'SVG', 'MATH']);
    const SAFE_URL = /^(https?:|mailto:|tel:|\/|#|\.{0,2}\/|[^:]*$)/i;

    function clean(node) {
        Array.prototype.slice.call(node.childNodes).forEach((child) => {
            if (child.nodeType === Node.COMMENT_NODE) {
                child.remove();
                return;
            }

            if (child.nodeType !== Node.ELEMENT_NODE) {
                return;
            }

            const tag = child.tagName.toUpperCase();

            if (DROP_WITH_CONTENT.has(tag)) {
                child.remove();
                return;
            }

            if (!ALLOWED_TAGS.has(tag)) {
                // Unbekannte Elemente durch ihren Inhalt ersetzen - der Text bleibt lesbar.
                clean(child);
                child.replaceWith.apply(child, Array.prototype.slice.call(child.childNodes));
                return;
            }

            Array.prototype.slice.call(child.attributes).forEach((attribute) => {
                const name = attribute.name.toLowerCase();
                const keep = (tag === 'A' && (name === 'href' || name === 'title'))
                    || ((tag === 'TH' || tag === 'TD') && name === 'align')
                    || (tag === 'OL' && name === 'start');

                if (!keep || (name === 'href' && !SAFE_URL.test(attribute.value.trim()))) {
                    child.removeAttribute(attribute.name);
                }
            });

            clean(child);
        });
    }

    return {
        /** @returns {string} bereinigtes HTML */
        toHtml(markdown) {
            const html = typeof marked !== 'undefined' ? marked.parse(markdown || '') : null;
            const template = document.createElement('template');

            if (html === null) {
                // Ohne Markdown-Parser als reiner Text.
                const p = document.createElement('p');
                p.textContent = markdown || '';
                return p.outerHTML;
            }

            template.innerHTML = html;
            clean(template.content);

            return template.innerHTML;
        },
    };
})();

/**
 * Quellen, die als Link angezeigt werden duerfen.
 *
 * Quellen ohne Adresse - etwa Zusatzinhalte aus Dateien - werden gar nicht angezeigt: Ein
 * Dateiname hilft Besuchern nicht weiter und verriete womoeglich interne Bezeichnungen.
 */
window.ChatbotSources = window.ChatbotSources || {
    /** @returns {Array<{title: string, url: string}>} */
    linkable(sources) {
        if (!Array.isArray(sources)) {
            return [];
        }

        return sources.filter((source) => source && typeof source.url === 'string' && /^(https?:\/\/|\/(?!\/))/i.test(source.url.trim()));
    },

    /** Baut die Liste; ohne verlinkbare Quelle null. */
    list(sources, className, max) {
        const linkable = this.linkable(sources).slice(0, max || 3);

        if (linkable.length === 0) {
            return null;
        }

        const list = document.createElement('ul');
        list.className = className;

        linkable.forEach((source) => {
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.href = source.url.trim();
            a.target = '_blank';
            a.rel = 'noopener';
            a.textContent = source.title || source.url;
            li.appendChild(a);
            list.appendChild(li);
        });

        return list;
    },
};

/**
 * Daumen hoch/runter unter einer Antwort, beim Daumen nach unten ein kurzes Textfeld.
 *
 * Adressiert wird die Antwort ueber ihre zufaellige Kennung `ref` aus dem Ereignis `meta` des
 * Antwortstroms. Alles, was hier angezeigt wird, geht ueber textContent.
 */
window.ChatbotFeedback = window.ChatbotFeedback || {
    url: '/bluebranch/chatbot/api/v1/feedback',
    maxLength: 1000,

    /**
     * @param {HTMLElement} target   Element, an das die Leiste angehaengt wird
     * @param {Object} options       {ref, rating, strings, requestToken, onChange(rating)}
     * @returns {HTMLElement}
     */
    render(target, options) {
        const strings = Object.assign({
            feedbackQuestion: 'War die Antwort hilfreich?',
            feedbackUp: 'Hilfreich',
            feedbackDown: 'Nicht hilfreich',
            feedbackPlaceholder: 'Was hat nicht gepasst? (optional)',
            feedbackHint: 'Bitte keine persönlichen Daten eingeben.',
            feedbackSend: 'Senden',
            feedbackThanks: 'Danke für Ihr Feedback!',
            feedbackError: 'Feedback konnte nicht gesendet werden.',
        }, options.strings || {});

        const bar = document.createElement('div');
        bar.className = 'chatbot-feedback';

        const label = document.createElement('span');
        label.className = 'chatbot-feedback__label';
        label.textContent = strings.feedbackQuestion;
        bar.appendChild(label);

        const status = document.createElement('p');
        status.className = 'chatbot-feedback__thanks';
        status.setAttribute('role', 'status');
        status.hidden = true;

        let form = null;

        const button = (rating, text, symbol) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'chatbot-feedback__btn chatbot-feedback__btn--' + rating;
            btn.setAttribute('aria-label', text);
            btn.setAttribute('title', text);
            btn.setAttribute('aria-pressed', options.rating === rating ? 'true' : 'false');
            btn.textContent = symbol;
            btn.addEventListener('click', () => choose(rating));
            bar.appendChild(btn);
            return btn;
        };

        const showStatus = (text, isError) => {
            status.textContent = text;
            status.classList.toggle('chatbot-feedback__thanks--error', !!isError);
            status.hidden = false;
        };

        const send = (rating, comment) => this.send(options.ref, rating, comment, options.requestToken)
            .then(() => {
                if (typeof options.onChange === 'function') {
                    options.onChange(rating);
                }
                return true;
            })
            .catch(() => {
                showStatus(strings.feedbackError, true);
                return false;
            });

        const up = button('up', strings.feedbackUp, '👍');
        const down = button('down', strings.feedbackDown, '👎');

        const choose = (rating) => {
            up.setAttribute('aria-pressed', rating === 'up' ? 'true' : 'false');
            down.setAttribute('aria-pressed', rating === 'down' ? 'true' : 'false');
            status.hidden = true;

            if (form) {
                form.remove();
                form = null;
            }

            send(rating, '').then((ok) => {
                if (!ok) return;

                if (rating === 'up') {
                    showStatus(strings.feedbackThanks);
                    return;
                }

                form = this.commentForm(strings, (comment) => {
                    send('down', comment).then((sent) => {
                        if (sent && form) {
                            form.remove();
                            form = null;
                            showStatus(strings.feedbackThanks);
                        }
                    });
                });
                bar.after(form);
                form.querySelector('textarea').focus();
            });
        };

        target.appendChild(bar);
        target.appendChild(status);

        return bar;
    },

    commentForm(strings, onSubmit) {
        const form = document.createElement('form');
        form.className = 'chatbot-feedback__form';

        const textarea = document.createElement('textarea');
        textarea.className = 'chatbot-feedback__input';
        textarea.rows = 2;
        textarea.maxLength = this.maxLength;
        textarea.placeholder = strings.feedbackPlaceholder;
        textarea.setAttribute('aria-label', strings.feedbackPlaceholder);

        const hint = document.createElement('small');
        hint.className = 'chatbot-feedback__hint';
        hint.textContent = strings.feedbackHint;

        const submit = document.createElement('button');
        submit.type = 'submit';
        submit.className = 'chatbot-feedback__send';
        submit.textContent = strings.feedbackSend;

        form.appendChild(textarea);
        form.appendChild(hint);
        form.appendChild(submit);

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const comment = textarea.value.trim().slice(0, this.maxLength);

            if (comment === '') {
                form.remove();
                return;
            }

            submit.disabled = true;
            onSubmit(comment);
        });

        return form;
    },

    send(ref, rating, comment, preset) {
        return ChatbotStreamToken.get(preset).then((token) => fetch(this.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': token,
            },
            body: JSON.stringify({ ref: ref, rating: rating, comment: comment || '' }),
        })).then((response) => {
            if (!response.ok) {
                throw new Error('Feedback failed (' + response.status + ')');
            }
            return response.json();
        });
    },
};

/**
 * Chatverlauf als Datei: Text oder WebVTT.
 *
 * Eintraege: {role, content, time?, end?}. `time` ist der Beginn einer Nachricht, `end` bei
 * Antworten der Zeitpunkt, zu dem sie fertig war. Eintraege ohne Zeitstempel (Verlaeufe von vor
 * 1.3.0) werden einzeln ueberbrueckt - im Abstand einer Sekunde zum Nachbarn -, statt die Zeiten
 * der ganzen Datei aufzugeben.
 */
window.ChatbotExport = window.ChatbotExport || {
    /** Beginn je Eintrag in ms, aufsteigend. */
    times(entries) {
        const valid = (entry) => entry && typeof entry.time === 'number' && entry.time > 0;
        const firstTimed = entries.findIndex(valid);
        const anchor = firstTimed === -1 ? Date.now() : entries[firstTimed].time;
        const times = [];

        entries.forEach((entry, i) => {
            if (firstTimed === -1 || i < firstTimed) {
                times.push(anchor - ((firstTimed === -1 ? entries.length : firstTimed) - i) * 1000);
                return;
            }

            const previous = i > 0 ? times[i - 1] : null;
            times.push(valid(entry) && (previous === null || entry.time >= previous) ? entry.time : previous + 1000);
        });

        return times;
    },

    /**
     * Wie lange ein Untertitel steht: das Ende der Antwort plus Lesezeit (50 ms je Zeichen,
     * mindestens 2, hoechstens 20 Sekunden), aber nicht ueber den Beginn der naechsten Nachricht
     * hinaus - sonst stapeln Player die Zeilen uebereinander.
     */
    cueEnds(entries, times) {
        return entries.map((entry, i) => {
            const shown = typeof entry.end === 'number' && entry.end >= times[i] ? entry.end : times[i];
            const reading = Math.min(20000, Math.max(2000, entry.content.length * 50));
            let end = shown + reading;

            if (i + 1 < times.length && times[i + 1] < end) {
                end = times[i + 1];
            }

            return Math.max(end, times[i] + 500);
        });
    },

    pad(value, length) {
        return String(value).padStart(length || 2, '0');
    },

    /**
     * Markdown der Antworten als lesbarer Text: Links als „Text (URL)“, Hervorhebungen,
     * Ueberschriften- und Codezeichen entfernt, Listen mit „- “.
     */
    plain(text) {
        return String(text)
            .replace(/```[a-z]*\n?/gi, '')
            .replace(/!\[([^\]]*)\]\([^)]*\)/g, '$1')
            .replace(/\[([^\]]+)\]\(([^)\s]+)[^)]*\)/g, '$1 ($2)')
            .replace(/(\*\*|__)(.+?)\1/g, '$2')
            .replace(/(^|[^*\w])[*_]([^*_\n]+)[*_](?=[^*\w]|$)/g, '$1$2')
            .replace(/`([^`]+)`/g, '$1')
            .replace(/^\s{0,3}#{1,6}\s+/gm, '')
            .replace(/^\s*[*+]\s+/gm, '- ')
            .replace(/^\s{0,3}>\s?/gm, '');
    },

    clock(ms) {
        const date = new Date(ms);
        return this.pad(date.getHours()) + ':' + this.pad(date.getMinutes()) + ':' + this.pad(date.getSeconds());
    },

    txt(entries, speaker, title) {
        const times = this.times(entries);
        const lines = [title + ' – ' + new Date(times[0]).toLocaleString(), ''];

        entries.forEach((entry, i) => {
            lines.push('[' + this.clock(times[i]) + '] ' + speaker(entry) + ': ' + this.plain(entry.content).trim());
            lines.push('');
        });

        return lines.join('\r\n');
    },

    vttTime(ms) {
        const hours = Math.floor(ms / 3600000);
        const minutes = Math.floor((ms % 3600000) / 60000);
        const seconds = Math.floor((ms % 60000) / 1000);

        return this.pad(hours) + ':' + this.pad(minutes) + ':' + this.pad(seconds) + '.' + this.pad(ms % 1000, 3);
    },

    /**
     * Cue-Text darf weder „-->“ noch Leerzeilen enthalten (beides beendet den Cue), und `<`/`&`
     * leiten Tags bzw. Entitaeten ein.
     */
    vttText(text) {
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\r\n?/g, '\n')
            .replace(/\n\s*\n+/g, '\n')
            .trim();
    },

    /**
     * Zeitachse ab Beginn des Chats, wie Untertitel es verlangen. Die echte Uhrzeit steht als
     * Kennung ueber jedem Cue und das Startdatum in einer NOTE am Anfang.
     */
    vtt(entries, speaker) {
        const times = this.times(entries);
        const ends = this.cueEnds(entries, times);
        const start = times[0];
        const blocks = ['WEBVTT', '', 'NOTE Chat vom ' + new Date(start).toLocaleString(), ''];

        entries.forEach((entry, i) => {
            const name = this.vttText(speaker(entry)).replace(/\n/g, ' ');

            blocks.push((i + 1) + ' ' + this.clock(times[i]));
            blocks.push(this.vttTime(times[i] - start) + ' --> ' + this.vttTime(ends[i] - start));
            blocks.push('<v ' + name + '>' + this.vttText(this.plain(entry.content)));
            blocks.push('');
        });

        return blocks.join('\n');
    },

    download(filename, content, type) {
        const blob = new Blob(['﻿' + content], { type: type + ';charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');

        link.href = url;
        link.download = filename;
        link.rel = 'noopener';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    },
};

/**
 * Spracheingabe: nimmt mit dem Mikrofon auf und laesst die Aufnahme vom Server in Text umwandeln.
 *
 * - Nur im sicheren Kontext (HTTPS oder localhost); sonst bietet `supported()` false.
 * - Die Aufnahme endet von selbst nach `maxSeconds` (Grenze der Stufe des Kontos).
 * - Nichts wird im Browser gespeichert: Der Puffer lebt bis zum Absenden.
 * - Der API-Schluessel bleibt auf dem Server; hier gehen Aufnahme, Seite, Modul und Signatur
 *   an die Route der Erweiterung.
 */
window.ChatbotVoice = window.ChatbotVoice || {
    CANDIDATES: ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'],

    supported() {
        return typeof window !== 'undefined'
            && window.isSecureContext === true
            && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)
            && typeof MediaRecorder !== 'undefined';
    },

    /**
     * @param {{maxSeconds: number, onTick: function(number): void, onStop: function(): void,
     *          onBlob: function(Blob, string): void, onError: function(string): void,
     *          onLevel?: function(number): void}} options
     *        `onLevel` bekommt rund 15-mal pro Sekunde den Pegel (0 bis 1) fuer die Wellenanzeige.
     *        `onError` bekommt `denied` oder `failed`.
     * @returns {{start: function(): Promise<void>, stop: function(): void, cancel: function(): void,
     *            isRecording: function(): boolean}}
     */
    record(options) {
        let recorder = null;
        let stream = null;
        let chunks = [];
        let timer = null;
        let elapsed = 0;
        let cancelled = false;
        let audioContext = null;
        let levelTimer = null;

        const release = () => {
            if (levelTimer) {
                clearInterval(levelTimer);
                levelTimer = null;
            }
            if (audioContext) {
                audioContext.close().catch(() => {});
                audioContext = null;
            }
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
            if (stream) {
                stream.getTracks().forEach((track) => track.stop());
                stream = null;
            }
            recorder = null;
            chunks = [];
        };

        const stop = () => {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
            if (recorder && recorder.state !== 'inactive') {
                recorder.stop();
            }
        };

        return {
            isRecording: () => !!recorder && recorder.state === 'recording',
            stop: stop,
            cancel() {
                cancelled = true;
                if (recorder && recorder.state !== 'inactive') {
                    recorder.stop();
                } else {
                    release();
                }
            },
            async start() {
                cancelled = false;

                try {
                    stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                } catch (e) {
                    release();
                    options.onError('denied');
                    return;
                }

                const mimeType = ChatbotVoice.CANDIDATES.find((type) => MediaRecorder.isTypeSupported(type));

                try {
                    // Sprache braucht wenig: 32 kbit/s halten die Aufnahme klein.
                    recorder = new MediaRecorder(stream, Object.assign({ audioBitsPerSecond: 32000 }, mimeType ? { mimeType: mimeType } : {}));
                } catch (e) {
                    release();
                    options.onError('failed');
                    return;
                }

                chunks = [];
                recorder.ondataavailable = (event) => {
                    if (event.data && event.data.size) {
                        chunks.push(event.data);
                    }
                };
                recorder.onstop = () => {
                    const type = (recorder && recorder.mimeType) || mimeType || 'audio/webm';
                    const blob = new Blob(chunks, { type: type });
                    const wasCancelled = cancelled;

                    release();
                    options.onStop();

                    if (!wasCancelled && blob.size) {
                        options.onBlob(blob, type);
                    }
                };

                // Pegel fuer die Wellenanzeige. Fehlt Web Audio, nimmt die Aufnahme trotzdem auf.
                if (options.onLevel) {
                    try {
                        const Context = window.AudioContext || window.webkitAudioContext;
                        audioContext = new Context();
                        const analyser = audioContext.createAnalyser();
                        analyser.fftSize = 512;
                        audioContext.createMediaStreamSource(stream).connect(analyser);
                        const samples = new Uint8Array(analyser.fftSize);

                        levelTimer = setInterval(() => {
                            analyser.getByteTimeDomainData(samples);
                            let sum = 0;
                            for (let i = 0; i < samples.length; i++) {
                                const v = (samples[i] - 128) / 128;
                                sum += v * v;
                            }
                            // Sprache liegt meist bei einem RMS von 0,05 bis 0,3.
                            options.onLevel(Math.min(1, Math.sqrt(sum / samples.length) * 3.2));
                        }, 70);
                    } catch (e) {
                        audioContext = null;
                    }
                }

                elapsed = 0;
                recorder.start();
                options.onTick(0);
                timer = setInterval(() => {
                    elapsed += 1;
                    options.onTick(elapsed);
                    if (elapsed >= options.maxSeconds) {
                        stop();
                    }
                }, 1000);
            },
        };
    },

    /**
     * Schickt die Aufnahme an die Route der Erweiterung.
     *
     * @param {{url?: string, token: string, pageId: string, moduleId: string|number, sig: string, language: string}} config
     * @returns {Promise<string>} der erkannte Text (kann leer sein); bei Fehlern eine Error mit lesbarer Meldung
     */
    transcribe(blob, config) {
        const form = new FormData();
        form.append('audio', blob, 'aufnahme');
        form.append('language', config.language || 'de');

        if (config.pageId) {
            form.append('pageId', config.pageId);
        }
        if (config.moduleId) {
            form.append('moduleId', config.moduleId);
        }
        if (config.sig) {
            form.append('sig', config.sig);
        }

        return fetch(config.url || '/bluebranch/chatbot/api/v1/transcribe', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': config.token, 'Accept': 'application/json' },
            body: form,
        }).then((response) => response.json().catch(() => ({})).then((data) => {
            if (!response.ok || !data || data.success !== true) {
                throw new Error(data && data.message ? data.message : '');
            }

            return typeof data.text === 'string' ? data.text.trim() : '';
        }));
    },
};

/**
 * Mikrofon-Knopf in einem Eingabefeld (Frage-Modul, Suche): nimmt auf, zeigt Wellen an die Stelle
 * des Textfelds, laesst die Aufnahme in Text umwandeln und uebergibt ihn an `onText`.
 *
 * Bei Stille (durchgehend fast kein Pegel) geht nichts an die API - Whisper erfindet dann gern Saetze.
 * Fehlt dem Browser die Aufnahme (oder HTTPS), bleibt der Knopf verborgen.
 *
 * @param {{wrap: Element, form: Element, input: HTMLInputElement, button: HTMLElement, maxSeconds: number,
 *          request: {pageId: string, moduleId: number|string, sig: string, language: string},
 *          strings?: Object, token?: string, onText: function(string): void}} o
 * @returns {{setBusy: function(boolean): void, cancel: function(): void}|null}
 */
window.ChatbotVoiceField = window.ChatbotVoiceField || {
    attach(o) {
        if (!window.ChatbotVoice || !ChatbotVoice.supported() || !o.button || !o.wrap || !(o.maxSeconds > 0)) {
            return null;
        }

        const t = Object.assign({
            voice: 'Spracheingabe starten',
            voiceStop: 'Aufnahme beenden und senden',
            voiceCancel: 'Aufnahme verwerfen',
            voiceWorking: 'Wird verstanden …',
            voiceEmpty: 'Es wurde nichts verstanden. Bitte noch einmal sprechen.',
            voiceDenied: 'Kein Zugriff auf das Mikrofon. Bitte in der Adresszeile des Browsers erlauben.',
            voiceError: 'Die Spracheingabe hat nicht funktioniert.',
        }, o.strings || {});

        const BARS = 30;
        const levels = new Array(BARS).fill(0);
        let state = 'idle';
        let busy = false;
        let recorder = null;
        let peak = 0;
        let measured = false;
        let token = o.token || '';

        // Wellen-Leiste: liegt ueber dem Textfeld, solange aufgenommen oder erkannt wird.
        const wave = document.createElement('div');
        wave.className = 'chatbot-voice-wave';
        wave.hidden = true;

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'chatbot-widget__wave-cancel';
        cancel.innerHTML = '&times;';
        cancel.setAttribute('aria-label', t.voiceCancel);
        cancel.setAttribute('title', t.voiceCancel);

        const barsEl = document.createElement('div');
        barsEl.className = 'chatbot-widget__wave-bars';
        barsEl.setAttribute('aria-hidden', 'true');
        const bars = [];
        for (let i = 0; i < BARS; i++) {
            const bar = document.createElement('span');
            bar.style.animationDelay = (i % 12) * 80 + 'ms';
            barsEl.appendChild(bar);
            bars.push(bar);
        }

        const time = document.createElement('span');
        time.className = 'chatbot-widget__wave-time';

        wave.append(cancel, barsEl, time);
        o.wrap.appendChild(wave);
        o.wrap.classList.add('has-voice');

        // Rueckmeldungen (nichts verstanden, Mikrofon verweigert ...) unter dem Formular.
        const note = document.createElement('div');
        note.className = 'chatbot-voice-note';
        note.setAttribute('role', 'status');
        note.hidden = true;
        if (o.form && o.form.parentNode) {
            o.form.parentNode.insertBefore(note, o.form.nextSibling);
        }

        const say = (message) => {
            note.textContent = message || '';
            note.hidden = !message;
        };

        const clock = (seconds) => Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');

        const setState = (next) => {
            state = next;
            const recording = next === 'recording';
            const working = next === 'working';
            const label = recording ? t.voiceStop : t.voice;

            o.button.classList.toggle('chatbot-voice-btn--recording', recording);
            o.button.setAttribute('aria-label', label);
            o.button.setAttribute('title', label);
            o.button.setAttribute('aria-pressed', recording ? 'true' : 'false');
            o.button.disabled = working || (next === 'idle' && busy);

            wave.hidden = !(recording || working);
            wave.classList.toggle('chatbot-widget__wave--working', working);
            cancel.hidden = working;
            o.wrap.classList.toggle('is-voice', recording || working);

            if (working) {
                time.textContent = t.voiceWorking;
            }
            if (next === 'idle') {
                levels.fill(0);
                bars.forEach((bar) => { bar.style.transform = ''; });
            }
        };

        const push = (level) => {
            levels.shift();
            levels.push(level);
            bars.forEach((bar, i) => { bar.style.transform = 'scaleY(' + Math.max(0.1, levels[i]).toFixed(2) + ')'; });
        };

        const transcribe = (blob) => {
            setState('working');

            ChatbotStreamToken.get(token).then((value) => {
                token = value;

                return ChatbotVoice.transcribe(blob, {
                    token: token,
                    pageId: o.request.pageId,
                    moduleId: o.request.moduleId,
                    sig: o.request.sig,
                    language: o.request.language,
                });
            }).then((text) => {
                setState('idle');

                if (!text) {
                    say(t.voiceEmpty);
                    return;
                }

                o.onText(text);
            }).catch((error) => {
                setState('idle');
                say((error && error.message) || t.voiceError);
            });
        };

        const start = () => {
            if (state !== 'idle' || busy) return;

            say('');
            peak = 0;
            measured = false;
            recorder = ChatbotVoice.record({
                maxSeconds: o.maxSeconds,
                onTick: (seconds) => { time.textContent = clock(seconds) + ' / ' + clock(o.maxSeconds); },
                onLevel: (level) => { measured = true; peak = Math.max(peak, level); push(level); },
                onStop: () => { if (state === 'recording') setState('idle'); },
                onBlob: (blob) => {
                    // Durchgehend still: nichts an die API, kein Kontingent.
                    if (measured && peak < 0.04) {
                        say(t.voiceEmpty);
                        return;
                    }

                    transcribe(blob);
                },
                onError: (kind) => {
                    setState('idle');
                    say(kind === 'denied' ? t.voiceDenied : t.voiceError);
                },
            });

            // Vor dem Start gesperrt, damit ein zweiter Klick waehrend der Mikrofon-Abfrage nichts doppelt startet.
            setState('recording');
            recorder.start();
        };

        o.button.hidden = false;
        o.button.addEventListener('click', () => {
            if (state === 'recording') {
                recorder.stop();
                return;
            }
            start();
        });
        cancel.addEventListener('click', () => { if (state === 'recording' && recorder) recorder.cancel(); });
        o.wrap.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && state === 'recording' && recorder) recorder.cancel();
        });
        window.addEventListener('pagehide', () => { if (state === 'recording' && recorder) recorder.cancel(); });

        return {
            setBusy(value) {
                busy = !!value;
                o.button.disabled = state === 'working' || (state === 'idle' && busy);
            },
            cancel() {
                if (state === 'recording' && recorder) recorder.cancel();
            },
        };
    },
};
