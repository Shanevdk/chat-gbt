<?php

namespace App\Ai;

/**
 * Collects what the companion's tools decide to send while she is replying,
 * so the photos can be delivered in order alongside her texts.
 */
class CompanionOutbox
{
    /**
     * Photos she sent, keyed by the tool call that sent them.
     *
     * @var array<string, array{path: string, caption: string}>
     */
    private array $photos = [];

    /**
     * Whether she decided to start a voice call.
     */
    public bool $startsCall = false;

    public function addPhoto(?string $toolCallId, string $path, string $caption): void
    {
        $this->photos[$toolCallId ?? 'photo-'.count($this->photos)] = ['path' => $path, 'caption' => $caption];
    }

    /**
     * Take the photo sent by the given tool call, if there was one.
     *
     * @return array{path: string, caption: string}|null
     */
    public function pullPhoto(?string $toolCallId): ?array
    {
        if ($toolCallId === null || ! isset($this->photos[$toolCallId])) {
            return null;
        }

        $photo = $this->photos[$toolCallId];
        unset($this->photos[$toolCallId]);

        return $photo;
    }

    /**
     * Take every photo that has not been delivered yet.
     *
     * @return list<array{path: string, caption: string}>
     */
    public function pullRemainingPhotos(): array
    {
        $photos = array_values($this->photos);
        $this->photos = [];

        return $photos;
    }
}
