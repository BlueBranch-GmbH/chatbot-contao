/**
 * Holt den Sitzungs-Token fuer die Antwort-Routen erst, wenn wirklich gefragt wird.
 *
 * Stuende der Token im Seiten-HTML, muesste jede Seite mit Chatbot eine Session anlegen -
 * und Contao liefert Antworten mit Session-Cookie nie aus dem HTTP-Cache. So bekommt nur,
 * wer den Chatbot tatsaechlich benutzt, eine Session.
 *
 * Mehrere Module auf einer Seite teilen sich die Anfrage; das Skript darf auch mehrfach
 * eingebunden sein.
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
