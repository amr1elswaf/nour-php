<?php

declare(strict_types=1);

namespace Nour\WebSocket\Events;

use Nour\Events\Event;
use Swoole\Http\Request;

/**
 * Fired BEFORE the framework registers a new WebSocket connection.
 *
 * Listeners can:
 *
 *   - **Read** the incoming HTTP upgrade request — headers, query
 *     string, cookies, the API key extracted upstream — and decide
 *     whether to accept.
 *
 *   - **Reject** the connection by calling `reject($code, $message)`
 *     and `stopPropagation()`. The framework returns the HTTP status
 *     and message to the client and closes the socket without ever
 *     calling `addSocket()`.
 *
 *   - **Attach data** to the connection by calling `setUserId()`,
 *     `setToken()`, and `setUserData()`. Whatever's set here is
 *     persisted into the {@see \Nour\Contracts\WebSocket\SocketStoreInterface}
 *     and becomes available throughout the connection's lifetime
 *     via `getSocketInfo()` and friends.
 *
 * ## Example: gooabb-style auth via listener
 *
 * ```php
 * App::events()->addListener(HandshakeEvent::class, function (HandshakeEvent $e) {
 *     if ($e->getApiKey() === '') {
 *         $e->reject(401, 'API key required');
 *         $e->stopPropagation();
 *         return;
 *     }
 *     $user = $myResolver->resolveByApiKey($e->getApiKey());
 *     if ($user === null) {
 *         $e->reject(401, 'Invalid API key');
 *         $e->stopPropagation();
 *         return;
 *     }
 *     $e->setUserId($user->id);
 *     $e->setToken($e->getApiKey());
 *     $e->setUserData(['role' => $user->role, 'name' => $user->name]);
 * });
 * ```
 *
 * Without any listener, the connection is accepted as anonymous
 * (user_id 0, no token) — which is fine for public services that
 * don't need per-user identity.
 */
final class HandshakeEvent extends Event
{
    private int|string $userId = 0;
    private string $token = '';

    /** @var array<string, mixed> */
    private array $userData = [];

    private bool $rejected = false;
    private int $rejectionCode = 401;
    private string $rejectionMessage = 'Unauthorized';

    public function __construct(
        public readonly Request $request,
        public readonly string $ip,
        public readonly string $apiKey,
    ) {}

    // ── Identity setters (called by listeners that recognise the user)

    public function setUserId(int|string $userId): void
    {
        $this->userId = $userId;
    }

    public function setToken(string $token): void
    {
        $this->token = $token;
    }

    /** @param array<string, mixed> $data */
    public function setUserData(array $data): void
    {
        $this->userData = $data;
    }

    public function mergeUserData(array $data): void
    {
        $this->userData = array_merge($this->userData, $data);
    }

    // ── Identity getters (used by the framework after listeners run)

    public function getUserId(): int|string { return $this->userId; }
    public function getToken(): string { return $this->token; }
    /** @return array<string, mixed> */
    public function getUserData(): array { return $this->userData; }
    public function getApiKey(): string { return $this->apiKey; }

    // ── Rejection

    /**
     * Reject the handshake. Combine with {@see stopPropagation()} to
     * skip remaining listeners and short-circuit the framework.
     */
    public function reject(int $code = 401, string $message = 'Unauthorized'): void
    {
        $this->rejected         = true;
        $this->rejectionCode    = $code;
        $this->rejectionMessage = $message;
    }

    public function isRejected(): bool { return $this->rejected; }
    public function getRejectionCode(): int { return $this->rejectionCode; }
    public function getRejectionMessage(): string { return $this->rejectionMessage; }
}
