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
        this.response = this.container.querySelector('.chatbot-response');

        this.search = new ChatbotSearch({
            containerId: config.containerId,
            query: '',
            requestToken: config.requestToken,
            language: config.language,
            pageId: config.pageId
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
}
