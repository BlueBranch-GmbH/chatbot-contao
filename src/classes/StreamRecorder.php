<?php

namespace Bluebranch\Chatbot\classes;

/**
 * Liest den Antwortstrom der API mit, waehrend er an den Browser weitergeht.
 *
 * Die Chunks des HTTP-Clients richten sich nicht nach den SSE-Bloecken: Ein Block kann ueber
 * zwei Chunks verteilt ankommen, ein Chunk mehrere Bloecke enthalten. Deshalb wird gepuffert
 * und nur an Leerzeilen getrennt.
 */
class StreamRecorder
{
    /** Obergrenze, damit eine entgleiste Gegenstelle den Speicher nicht fuellt. */
    private const MAX_ANSWER = 200000;

    private string $buffer = '';
    private string $answer = '';
    private array $sources = [];

    /** @var \Closure(array): array|null */
    private ?\Closure $sourceFilter;

    /**
     * @param \Closure(array): array|null $sourceFilter Wählt aus der Quellenliste, was der Besucher sehen darf
     */
    public function __construct(?\Closure $sourceFilter = null)
    {
        $this->sourceFilter = $sourceFilter;
    }

    /**
     * Nimmt Rohdaten der API entgegen und gibt zurueck, was an den Browser weitergehen darf:
     * die vollstaendigen Bloecke (unveraendert, ausser der Quellenliste), nie einen halben.
     */
    public function feed(string $chunk): string
    {
        $this->buffer .= $chunk;
        $out = '';

        while (preg_match('/\r?\n\r?\n/', $this->buffer, $match, PREG_OFFSET_CAPTURE)) {
            $offset = $match[0][1];
            $block = substr($this->buffer, 0, $offset);
            $this->buffer = (string) substr($this->buffer, $offset + \strlen($match[0][0]));
            $out .= $this->parseBlock($block) . $match[0][0];
        }

        return $out;
    }

    /**
     * @return string Der Rest, der nicht mit einer Leerzeile endete
     */
    public function finish(): string
    {
        $rest = '';

        if ('' !== trim($this->buffer)) {
            $rest = $this->parseBlock($this->buffer);
        }

        $this->buffer = '';

        return $rest;
    }

    public function answer(): string
    {
        return $this->answer;
    }

    public function sources(): array
    {
        return $this->sources;
    }

    private function parseBlock(string $block): string
    {
        $event = 'message';
        $data = [];

        foreach (preg_split('/\r?\n/', $block) as $line) {
            if (0 === strncmp($line, 'event:', 6)) {
                $event = trim(substr($line, 6));
            } elseif (0 === strncmp($line, 'data:', 5)) {
                $data[] = ltrim(substr($line, 5), ' ');
            }
        }

        if ('message' !== $event || [] === $data) {
            return $block;
        }

        $payload = json_decode(implode("\n", $data), true);

        if (!\is_array($payload)) {
            return $block;
        }

        if (isset($payload['answer']) && \is_string($payload['answer']) && \strlen($this->answer) < self::MAX_ANSWER) {
            $this->answer .= $payload['answer'];
        }

        if (isset($payload['sources']) && \is_array($payload['sources'])) {
            if (null !== $this->sourceFilter) {
                $payload['sources'] = ($this->sourceFilter)($payload['sources']);
                $block = $this->withData($block, $payload);
            }

            $this->sources = $payload['sources'];
        }

        return $block;
    }

    /**
     * Ersetzt die `data:`-Zeilen eines Blocks, alle anderen Zeilen (etwa `id:`) bleiben.
     */
    private function withData(string $block, array $payload): string
    {
        $lines = array_filter(
            preg_split('/\r?\n/', $block),
            static fn (string $line): bool => 0 !== strncmp($line, 'data:', 5)
        );
        $lines[] = 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return implode("\n", $lines);
    }
}
