<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva;

use Glueful\Notifications\Contracts\Notifiable;

/**
 * PushFormatter
 *
 * Prepares a normalized push payload structure for different targets
 * (FCM, APNs, Web Push). Keeps the PushChannel lean.
 */
class PushFormatter
{
    /**
     * Format the base payload from generic notification data.
     *
     * @param array<string, mixed> $data
     */
    public function format(array $data, Notifiable $notifiable): array
    {
        $title = (string) ($data['title'] ?? ($data['subject'] ?? ''));
        $body  = (string) ($data['body'] ?? ($data['message'] ?? ''));
        $image = $data['image'] ?? null;
        $badge = $data['badge'] ?? null;
        $sound = $data['sound'] ?? 'default';
        $dataFields = (array) ($data['data'] ?? []);

        return [
            'title' => $title,
            'body' => $body,
            'image' => $image,
            'badge' => $badge,
            'sound' => $sound,
            'data' => $dataFields,
            'click_action' => $data['click_action'] ?? null,
            'topic' => $data['topic'] ?? null,
        ];
    }
}

