<?php

declare(strict_types=1);

namespace FEM\WordPress;

use Closure;

/** Temporarily establishes the paired WordPress user for APIs that check current_user_can(). */
final class CurrentUserScope
{
    /** @var Closure():int */
    private readonly Closure $currentUserId;

    /** @var Closure(int):void */
    private readonly Closure $setCurrentUser;

    private readonly bool $canSwitch;

    public function __construct(?callable $currentUserId = null, ?callable $setCurrentUser = null)
    {
        $this->canSwitch = $currentUserId !== null && $setCurrentUser !== null || function_exists('wp_set_current_user');
        $this->currentUserId = $currentUserId !== null
            ? Closure::fromCallable($currentUserId)
            : static fn (): int => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $this->setCurrentUser = $setCurrentUser !== null
            ? Closure::fromCallable($setCurrentUser)
            : static function (int $userId): void {
                if (function_exists('wp_set_current_user')) {
                    wp_set_current_user($userId);
                }
            };
    }

    public function runAs(int $userId, callable $callback): mixed
    {
        if ($userId <= 0 || !$this->canSwitch) {
            return $callback();
        }
        $previousUserId = ($this->currentUserId)();
        if ($previousUserId === $userId) {
            return $callback();
        }
        ($this->setCurrentUser)($userId);
        try {
            return $callback();
        } finally {
            ($this->setCurrentUser)($previousUserId);
        }
    }
}
