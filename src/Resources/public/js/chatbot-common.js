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
