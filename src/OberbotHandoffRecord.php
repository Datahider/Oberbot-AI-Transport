<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class OberbotHandoffRecord
{
    public function __construct(
        public readonly int $ticket_id,
        public readonly string $source_chat_id,
        public readonly int $source_thread_id,
        public readonly string $hidden_chat_id,
        public readonly ?int $hidden_thread_id = null,
        public readonly bool $log_sent = false,
        public readonly bool $ingress_sent = false
    ) {}

    public function opaque(): string
    {
        return $this->hidden_chat_id . ':' . (string) $this->hidden_thread_id;
    }

    public function withHiddenThread(int $hidden_thread_id): self
    {
        return new self($this->ticket_id, $this->source_chat_id, $this->source_thread_id, $this->hidden_chat_id, $hidden_thread_id, $this->log_sent, $this->ingress_sent);
    }

    public function withLogSent(): self
    {
        return new self($this->ticket_id, $this->source_chat_id, $this->source_thread_id, $this->hidden_chat_id, $this->hidden_thread_id, true, $this->ingress_sent);
    }

    public function withIngressSent(): self
    {
        return new self($this->ticket_id, $this->source_chat_id, $this->source_thread_id, $this->hidden_chat_id, $this->hidden_thread_id, $this->log_sent, true);
    }
}
