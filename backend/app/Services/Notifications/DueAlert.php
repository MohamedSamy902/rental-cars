<?php

namespace App\Services\Notifications;

/**
 * تنبيه مستحق واحد قبل إرساله للمستلمين.
 */
final class DueAlert
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $ruleKey,
        public string $subjectType,
        public int $subjectId,
        public string $title,
        public string $body,
        public ?string $linkType,
        public ?int $linkId,
        public array $permissions,
        public ?int $extraUserId = null,
    ) {}
}
