<?php

namespace App\Services;

use App\Models\NotificationRule;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Push\PushConfiguration;
use App\Push\PushResult;
use App\Push\PushSender;
use App\Queues\PushQueue;
use Cube\Data\Bunch;
use Cube\Env\Logger\Logger;

/**
 * Push notifications : the browsers of each user in each workspace, and the rules saying which events reach them.
 */
class PushService
{
    public const TEXT_RAW = 'raw';
    public const TEXT_KEY = 'key';
    public const TEXT_MODES = [self::TEXT_RAW, self::TEXT_KEY];

    public const MAX_TITLE_LENGTH = 100;
    public const MAX_BODY_LENGTH = 300;
    public const MAX_ATTEMPTS = 3;
    public const MAX_WAIT_SECONDS = 10;

    public function __construct(
        protected PushConfiguration $configuration,
        protected AccessKeyService $keys,
        protected WorkspaceService $workspaces,
    ) {}

    public function isEnabled(): bool
    {
        return $this->configuration->isEnabled();
    }

    public function publicKey(): ?string
    {
        return $this->isEnabled() ? $this->configuration->publicKey : null;
    }

    // Subscriptions

    /**
     * @return Bunch<int,PushSubscription> The browsers of this user enabled in this workspace, oldest first
     */
    public function subscriptionsOf(int $workspace, int $user): Bunch
    {
        return Bunch::wrap(PushSubscription::select()->where('workspace', $workspace)->where('user', $user)->order('id', 'ASC')->fetch());
    }

    public function findSubscription(int $workspace, int $user, int $id): ?PushSubscription
    {
        $subscription = PushSubscription::find($id);

        return $subscription && $subscription->workspace === $workspace && $subscription->user === $user ? $subscription : null;
    }

    /**
     * Enables a browser in one workspace : a known endpoint is updated there, never duplicated.
     */
    public function saveSubscription(int $workspace, int $user, string $endpoint, string $p256dh, string $auth, string $contentEncoding, ?string $userAgent): PushSubscription
    {
        $this->claimBrowser($user, $endpoint);

        // created_at as a string : generated models type a DATETIME column as DATE, which drops the time
        $subscription = PushSubscription::select()->where('endpoint', $endpoint)->where('workspace', $workspace)->first()
            ?? new PushSubscription(['endpoint' => $endpoint, 'workspace' => $workspace, 'created_at' => date('Y-m-d H:i:s')]);

        $subscription->user = $user;
        $subscription->p256dh = $p256dh;
        $subscription->auth = $auth;
        $subscription->content_encoding = $contentEncoding;
        $subscription->user_agent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);

        return $subscription->save();
    }

    /**
     * A browser belongs to the last user logged in on it : what someone else enabled there is dropped,
     * rather than sending them their notifications.
     */
    public function claimBrowser(int $user, string $endpoint): void
    {
        foreach (PushSubscription::select()->where('endpoint', $endpoint)->where('user', $user, '<>')->fetch() as $subscription)
            $this->deleteSubscription($subscription);
    }

    public function deleteSubscription(PushSubscription $subscription): void
    {
        PushSubscription::deleteId($subscription->id);
    }

    /**
     * @return bool false when the browser did not accept it
     */
    public function sendTest(PushSubscription $subscription, string $title, string $body): bool
    {
        return $this->deliverTo($subscription, [
            'title' => $this->truncate($title, self::MAX_TITLE_LENGTH),
            'body' => $this->truncate($body, self::MAX_BODY_LENGTH),
            'tag' => 'test',
            'url' => '/automation?tab=notifications',
        ]);
    }

    // Rules

    /**
     * @return Bunch<int,NotificationRule> The rules of this user in this workspace, oldest first
     */
    public function rulesOf(int $workspace, int $user): Bunch
    {
        return Bunch::wrap(NotificationRule::select()->where('workspace', $workspace)->where('user', $user)->order('id', 'ASC')->fetch());
    }

    public function findRule(int $workspace, int $user, int $id): ?NotificationRule
    {
        $rule = NotificationRule::find($id);

        return $rule && $rule->workspace === $workspace && $rule->user === $user ? $rule : null;
    }

    /**
     * @param list<string>                            $listen
     * @param null|array{mode: string, value: string} $title  null for the default title
     * @param null|array{mode: string, value: string} $body   null for the default body
     */
    public function createRule(int $workspace, int $user, string $name, array $listen, ?array $title, ?array $body): NotificationRule
    {
        $rule = new NotificationRule([
            'workspace' => $workspace,
            'user' => $user,
            'enabled' => true,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->fillRule($rule, $name, $listen, $title, $body)->save();
    }

    /**
     * @param list<string>                            $listen
     * @param null|array{mode: string, value: string} $title
     * @param null|array{mode: string, value: string} $body
     */
    public function updateRule(NotificationRule $rule, string $name, array $listen, ?array $title, ?array $body, bool $enabled): NotificationRule
    {
        $rule->enabled = $enabled;

        return $this->fillRule($rule, $name, $listen, $title, $body)->save();
    }

    public function deleteRule(NotificationRule $rule): void
    {
        NotificationRule::deleteId($rule->id);
    }

    /**
     * @return list<string>
     */
    public function listenOf(NotificationRule $rule): array
    {
        $listen = json_decode((string) $rule->listen, true);

        return is_array($listen) ? array_values(array_filter($listen, 'is_string')) : [];
    }

    /**
     * @return null|array{mode: string, value: string}
     */
    public function textOf(?string $stored): ?array
    {
        $text = null === $stored ? null : json_decode($stored, true);
        if (!is_array($text) || !in_array($text['mode'] ?? null, self::TEXT_MODES, true) || !is_string($text['value'] ?? null))
            return null;

        return ['mode' => $text['mode'], 'value' => $text['value']];
    }

    // Sending

    /**
     * Called for every event : only queued when a rule of the workspace may want it.
     *
     * @param string $payload JSON object
     */
    public function enqueue(int $workspace, int $history, string $event, string $payload): void
    {
        if (!$this->isEnabled())
            return;

        if (!NotificationRule::select()->where('workspace', $workspace)->where('enabled', true)->first())
            return;

        PushQueue::queue($workspace, $history, $event, $payload);
    }

    /**
     * Sends one notification per matching rule to every browser its user enabled in this workspace. The rules are read
     * again for each event : a change applies at once.
     *
     * @param string $payload JSON object
     */
    public function deliver(int $workspace, int $history, string $event, string $payload): void
    {
        if (!$this->isEnabled() || !$found = Workspace::find($workspace))
            return;

        $rules = NotificationRule::select()->where('workspace', $workspace)->where('enabled', true)->order('id', 'ASC')->fetch();

        foreach ($rules as $rule) {
            if (!$this->keys->listens($this->listenOf($rule), $event) || !$this->canReceive($rule->user, $workspace))
                continue;

            $message = [
                ...$this->content($this->textOf($rule->title), $this->textOf($rule->body), $found->name, $event, $payload),
                // A new notification of the same rule and event replaces the previous one
                'tag' => "rule-{$rule->id}-{$event}",
                'url' => "/history?workspace={$workspace}&line={$history}",
            ];

            foreach ($this->subscriptionsOf($workspace, $rule->user) as $subscription)
                $this->deliverTo($subscription, $message);
        }
    }

    /**
     * The text of a notification : each of the title and the body is a raw value, or the value of
     * a key of the payload. A missing or empty value falls back to the default text.
     *
     * @param null|array{mode: string, value: string} $title
     * @param null|array{mode: string, value: string} $body
     * @param string                                  $payload JSON object
     *
     * @return array{title: string, body: string}
     */
    public function content(?array $title, ?array $body, string $workspaceName, string $event, string $payload): array
    {
        $data = json_decode($payload, true);
        $data = is_array($data) ? $data : [];

        return [
            'title' => $this->truncate($this->resolve($title, $data) ?? "{$workspaceName} · {$event}", self::MAX_TITLE_LENGTH),
            'body' => $this->truncate($this->resolve($body, $data) ?? $this->defaultBody($data), self::MAX_BODY_LENGTH),
        ];
    }

    /**
     * The value at a dotted path of the payload (`author.name`), an object or a list written as JSON.
     */
    public function valueAt(array $payload, string $path): ?string
    {
        $current = $payload;
        foreach (explode('.', $path) as $key) {
            if (!is_array($current) || !array_key_exists($key, $current))
                return null;
            $current = $current[$key];
        }

        return match (true) {
            null === $current => null,
            is_bool($current) => $current ? 'true' : 'false',
            is_array($current) => json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $current,
        };
    }

    /**
     * @param array<string,mixed> $message
     *
     * @return bool true when the browser accepted it
     */
    private function deliverTo(PushSubscription $subscription, array $message): bool
    {
        $sender = PushSender::getInstance();

        for ($attempt = 1;; ++$attempt) {
            try {
                $result = $sender->send($subscription, $message);
            } catch (\Throwable $thrown) {
                Logger::getInstance()->warning('Cannot send a push notification to {subscription}', ['subscription' => $subscription->id]);
                Logger::getInstance()->logThrowable($thrown);

                return false;
            }

            if ($result->isSuccess()) {
                $subscription->last_success_at = date('Y-m-d H:i:s');
                $subscription->save();

                return true;
            }

            if ($result->isGone()) {
                $this->deleteSubscription($subscription);

                return false;
            }

            if (!$result->isRetryable() || $attempt >= self::MAX_ATTEMPTS) {
                Logger::getInstance()->warning('The push service refused a notification to {subscription} with {status}', ['subscription' => $subscription->id, 'status' => $result->status]);

                return false;
            }

            $sender->wait($this->waitBefore($result, $attempt));
        }
    }

    private function waitBefore(PushResult $result, int $attempt): int
    {
        return max(1, min($result->retryAfter ?? 2 ** $attempt, self::MAX_WAIT_SECONDS));
    }

    /**
     * Only a member of the workspace at the time of sending, whose account is active.
     */
    private function canReceive(int $user, int $workspace): bool
    {
        $found = User::find($user);

        return $found && !$found->disabled_at && $this->workspaces->isMember($user, $workspace);
    }

    /**
     * @param null|array{mode: string, value: string} $text
     */
    private function resolve(?array $text, array $payload): ?string
    {
        if (null === $text)
            return null;

        $value = self::TEXT_RAW === $text['mode'] ? $text['value'] : $this->valueAt($payload, $text['value']);

        return null === $value || '' === trim($value) ? null : $value;
    }

    /**
     * `payload.title` when there is one, else its first-level values.
     */
    private function defaultBody(array $payload): string
    {
        $title = $this->valueAt($payload, 'title');
        if (null !== $title && '' !== trim($title))
            return $title;

        $parts = [];
        foreach ($payload as $key => $value)
            $parts[] = "{$key}: ".$this->valueAt($payload, (string) $key);

        return implode(', ', $parts);
    }

    private function truncate(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) <= $length ? $text : rtrim(mb_substr($text, 0, $length - 1)).'…';
    }

    /**
     * @param list<string>                            $listen
     * @param null|array{mode: string, value: string} $title
     * @param null|array{mode: string, value: string} $body
     */
    private function fillRule(NotificationRule $rule, string $name, array $listen, ?array $title, ?array $body): NotificationRule
    {
        $rule->name = $name;
        $rule->listen = json_encode(array_values(array_unique($listen)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $rule->title = $this->encodeText($title);
        $rule->body = $this->encodeText($body);

        return $rule;
    }

    /**
     * @param null|array{mode: string, value: string} $text
     */
    private function encodeText(?array $text): ?string
    {
        if (null === $text)
            return null;

        return json_encode(['mode' => $text['mode'], 'value' => $text['value']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
