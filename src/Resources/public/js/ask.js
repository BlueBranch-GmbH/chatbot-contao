/**
 * Das Frage-Feld: getippter Platzhalter, Absenden ohne Seitenwechsel, Antwort darunter.
 *
 * Die Antwort selbst rendert ChatbotSearch -- gleicher Stream, gleiche Darstellung
 * wie beim Such-Modul, nur ohne Trefferliste der Contao-Suche.
 */
class ChatbotAsk {
    constructor(config) {
        this.container = document.getElementById(config.containerId);
        if (!this.container) return;

        this.form = this.container.querySelector('.chatbot-ask-form');
        this.input = this.container.querySelector('.chatbot-ask-input');
        this.submitButton = this.form ? this.form.querySelector('button[type="submit"], .submit') : null;

        // Waehrend der Antwort wird aus dem Absenden- ein Stopp-Knopf. Die
        // ursprüngliche Beschriftung steht im Template, sie wird hier gemerkt.
        this.labelSubmit = this.submitButton ? this.submitButton.textContent : '';
        this.labelStop = config.labelStop || 'Stopp';
        this.response = this.container.querySelector('.chatbot-response');

        this.search = new ChatbotSearch({
            containerId: config.containerId,
            query: '',
            requestToken: config.requestToken,
            language: config.language,
            pageId: config.pageId,
            onBusyChange: (busy) => this.setBusy(busy)
        });

        this.placeholder = new ChatbotTypedPlaceholder(this.input, config.questions || []);
    }

    init() {
        if (!this.form || !this.input) return;

        this.form.addEventListener('submit', (event) => {
            event.preventDefault();
            this.submit();
        });

        this.placeholder.start();
    }

    submit() {
        // Waehrend einer laufenden Antwort bricht derselbe Knopf sie ab, statt
        // eine zweite Anfrage in denselben Antwortbereich zu schicken. Das gilt
        // auch fuer die Eingabetaste im Feld, die hier ebenfalls ankommt.
        if (this.search.isBusy) {
            this.search.abort();
            return;
        }

        const question = this.input.value.trim();

        if (question === '') {
            this.input.focus();
            return;
        }

        // Ab der ersten Frage bleibt der Antwortbereich sichtbar; der getippte
        // Platzhalter hat dann ausgedient und wuerde nur vom Gelesenen ablenken.
        this.placeholder.stop();

        if (this.response) {
            this.response.hidden = false;
        }

        this.search.ask(question);
    }

    /**
     * Macht den Absenden-Knopf waehrend der Antwort zum Stopp-Knopf.
     */
    setBusy(busy) {
        if (this.submitButton) {
            this.submitButton.textContent = busy ? this.labelStop : this.labelSubmit;
            this.submitButton.classList.toggle('is-stop', busy);
        }

        if (this.form) {
            this.form.classList.toggle('is-busy', busy);
            this.form.setAttribute('aria-busy', busy ? 'true' : 'false');
        }
    }
}
