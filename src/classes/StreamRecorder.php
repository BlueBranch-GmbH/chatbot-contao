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

    public function feed(string $chunk): void
    {
        $this->buffer .= $chunk;

        while (preg_match('/\r?\n\r?\n/', $this->buffer, $match, PREG_OFFSET_CAPTURE)) {
            $offset = $match[0][1];
            $block = substr($this->buffer, 0, $offset);
            $this->buffer = (string) substr($this->buffer, $offset + \strlen($match[0][0]));
            $this->parseBlock($block);
        }
    }

    public function finish(): void
    {
        if ('' !== trim($this->buffer)) {
            $this->parseBlock($this->buffer);
        }

        $this->buffer = '';
    }

    public function answer(): string
    {
        return $this->answer;
    }

    public function sources(): array
    {
        return $this->sources;
    }

    private function parseBlock(string $block): void
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
            return;
        }

        $payload = json_decode(implode("\n", $data), true);

        if (!\is_array($payload)) {
            return;
        }

        if (isset($payload['answer']) && \is_string($payload['answer']) && \strlen($this->answer) < self::MAX_ANSWER) {
            $this->answer .= $payload['answer'];
        }

        if (isset($payload['sources']) && \is_array($payload['sources'])) {
            $this->sources = $payload['sources'];
        }
    }
}
