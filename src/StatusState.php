<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class StatusState
{
    public function __construct(
        public readonly string $opaque,
        public readonly bool $enabled,
        public readonly ?int $message_id,
        public readonly ?string $last_text,
        public readonly bool $voice_quote_enabled = false
    ) {
    }

    public function withMessage(?int $message_id, ?string $last_text): self
    {
        return new self($this->opaque, $this->enabled, $message_id, $last_text, $this->voice_quote_enabled);
    }

    public function withEnabled(bool $enabled): self
    {
        return new self($this->opaque, $enabled, $this->message_id, $this->last_text, $this->voice_quote_enabled);
    }

    public function withVoiceQuoteEnabled(bool $enabled): self
    {
        return new self($this->opaque, $this->enabled, $this->message_id, $this->last_text, $enabled);
    }
}
