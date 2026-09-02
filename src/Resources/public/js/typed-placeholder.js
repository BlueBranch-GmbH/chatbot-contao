/**
 * Tippt hinterlegte Fragen der Reihe nach in den Platzhalter eines Eingabefelds.
 *
 * Wird von zwei Modulen genutzt (chatbot_ask und dem Suchfeld von
 * chatbot_generate_search), deshalb kennt die Klasse nur das Feld und die Fragen.
 */
class ChatbotTypedPlaceholder {
    constructor(input, questions, options = {}) {
        this.input = input;
        this.questions = Array.isArray(questions) ? questions.filter(q => typeof q === 'string' && q.trim() !== '') : [];

        this.typeDelay = options.typeDelay || 45;
        this.deleteDelay = options.deleteDelay || 25;
        this.holdDelay = options.holdDelay || 1800;
        this.restDelay = options.restDelay || 400;

        // Der Platzhalter aus dem Markup ist die Rueckfalloption: Sobald die
        // Animation ruht oder gar nicht erst laeuft, steht wieder er im Feld.
        this.fallback = input ? input.getAttribute('placeholder') || '' : '';

        this.questionIndex = 0;
        this.charIndex = 0;
        this.deleting = false;
        this.timer = null;
        this.running = false;
    }

    /**
     * Startet nur, wenn es etwas zu tippen gibt und die Animation niemanden stoert:
     * bei "prefers-reduced-motion" bleibt der statische Platzhalter stehen.
     */
    start() {
        if (!this.input || this.questions.length === 0 || this.running) {
            return;
        }

        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            this.input.setAttribute('placeholder', this.questions[0]);
            return;
        }

        this.running = true;
        this.bindEvents();
        this.schedule(this.restDelay);
    }

    stop() {
        this.running = false;
        window.clearTimeout(this.timer);
        this.timer = null;

        if (this.input) {
            this.input.setAttribute('placeholder', this.fallback);
        }
    }

    /**
     * Waehrend jemand tippt oder das Feld gefuellt ist, haette ein wandernder
     * Platzhalter nichts zu suchen -- und im Hintergrund-Tab muss nichts laufen.
     */
    bindEvents() {
        this.input.addEventListener('focus', () => this.pause());
        this.input.addEventListener('blur', () => this.resume());
        this.input.addEventListener('input', () => {
            if (this.input.value !== '') {
                this.pause();
            }
        });

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.pause();
            } else {
                this.resume();
            }
        });
    }

    pause() {
        window.clearTimeout(this.timer);
        this.timer = null;
        this.charIndex = 0;
        this.deleting = false;

        if (this.input) {
            this.input.setAttribute('placeholder', this.fallback);
        }
    }

    resume() {
        if (!this.running || this.timer !== null || document.hidden) {
            return;
        }

        if (this.input && (this.input.value !== '' || this.input === document.activeElement)) {
            return;
        }

        this.schedule(this.restDelay);
    }

    schedule(delay) {
        this.timer = window.setTimeout(() => this.tick(), delay);
    }

    tick() {
        const question = this.questions[this.questionIndex];

        if (!this.deleting) {
            this.charIndex += 1;
            this.input.setAttribute('placeholder', question.slice(0, this.charIndex));

            if (this.charIndex >= question.length) {
                this.deleting = true;
                this.schedule(this.holdDelay);
                return;
            }

            this.schedule(this.typeDelay);
            return;
        }

        this.charIndex -= 1;
        this.input.setAttribute('placeholder', question.slice(0, Math.max(this.charIndex, 0)));

        if (this.charIndex <= 0) {
            this.deleting = false;
            this.questionIndex = (this.questionIndex + 1) % this.questions.length;
            this.schedule(this.restDelay);
            return;
        }

        this.schedule(this.deleteDelay);
    }
}
