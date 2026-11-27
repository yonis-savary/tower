<?php

namespace App\Services;

use App\Models\Webhook;
use App\Models\WebhookCall;
use Cube\Data\Bunch;
use Cube\Web\Http\Request;
use Symfony\Component\Uid\Uuid;

use function Cube\decrypt;
use function Cube\encrypt;

class WebhookService
{
    public const HEADER_SECRET = 'X-Tower-Secret';
    /** Signature headers, `sha256=<HMAC-SHA256 of the body>` : GitHub sends the first, Bitbucket the second */
    public const HEADERS_SIGNATURE = ['X-Hub-Signature-256', 'X-Hub-Signature'];

    public const CALL_ACCEPTED = 'accepted';
    public const CALL_UNAUTHORIZED = 'unauthorized';
    public const CALL_DISABLED = 'disabled';
    public const CALL_INVALID = 'invalid';

    public const MAX_EVENTS = 20;
    public const EVENT_PATTERN = '/^[a-z0-9_-]+(\.[a-z0-9_-]+)*$/';
    public const MAX_PAYLOAD_BYTES = 32768;

    private const PAGE_SIZE = 50;
    private const MAX_USER_AGENT_LENGTH = 255;

    public function __construct(
        protected EventService $events,
        protected WorkspaceSignals $signals,
    ) {}

    /**
     * @return Bunch<int,Webhook> Oldest first
     */
    public function listFor(int $workspace): Bunch
    {
        return Webhook::select()
            ->where('workspace', $workspace)
            ->order('id', 'ASC')
            ->toBunch();
    }

    public function find(int $workspace, int $id): ?Webhook
    {
        $webhook = Webhook::find($id);

        return $webhook && $webhook->workspace === $workspace ? $webhook : null;
    }

    public function findByUuid(string $uuid): ?Webhook
    {
        return Webhook::findWhere(['uuid' => strtolower($uuid)]);
    }

    /**
     * @param list<string> $events
     * @param bool $secured Chosen once : an unsecured webhook has no secret, its address alone triggers it
     *
     * @return array{0: Webhook, 1: ?string} The webhook and its secret, which the API never gives back afterwards
     */
    public function create(int $workspace, string $name, array $events, bool $secured = true): array
    {
        $secret = $secured ? $this->newSecret() : null;

        // created_at as a string : generated models type a DATETIME column as DATE, which drops the time
        $webhook = (new Webhook([
            'workspace' => $workspace,
            'name' => $name,
            'uuid' => Uuid::v4()->toRfc4122(),
            'secret' => null === $secret ? null : encrypt($secret),
            'events' => $this->encodeEvents($events),
            'enabled' => true,
            'secured' => $secured,
            'created_at' => date('Y-m-d H:i:s'),
        ]))->save();

        return [$webhook, $secret];
    }

    /**
     * @param list<string> $events
     */
    public function update(Webhook $webhook, string $name, array $events, bool $enabled): Webhook
    {
        $webhook->name = $name;
        $webhook->events = $this->encodeEvents($events);
        $webhook->enabled = $enabled;

        return $webhook->save();
    }

    /**
     * @return string The new secret : the previous one stops working at once
     */
    public function regenerateSecret(Webhook $webhook): string
    {
        if (!$webhook->secured)
            throw new \LogicException('An unsecured webhook has no secret');

        $secret = $this->newSecret();
        $webhook->secret = encrypt($secret);
        $webhook->save();

        return $secret;
    }

    /**
     * Its calls go with it : the events it triggered stay in the history.
     */
    public function delete(Webhook $webhook): void
    {
        WebhookCall::deleteWhere(['webhook' => $webhook->id]);
        Webhook::deleteId($webhook->id);
    }

    /**
     * @return list<string>
     */
    public function eventsOf(Webhook $webhook): array
    {
        $events = json_decode((string) $webhook->events, true);

        return is_array($events) ? array_values(array_filter($events, 'is_string')) : [];
    }

    /**
     * Checks a call, keeps a trace of it and, once accepted, triggers each event of the webhook
     * with the body of the call as payload.
     */
    public function receive(Webhook $webhook, Request $request): WebhookCall
    {
        $body = $request->getBody();

        if (!$this->isAuthenticated($webhook, $request, $body))
            return $this->trace($webhook, $request, self::CALL_UNAUTHORIZED);

        if (!$webhook->enabled)
            return $this->trace($webhook, $request, self::CALL_DISABLED, $body);

        $payload = $this->payloadOf($body);
        if (null === $payload)
            return $this->trace($webhook, $request, self::CALL_INVALID, $body);

        $call = $this->trace($webhook, $request, self::CALL_ACCEPTED, $body);
        $webhook->last_called_at = date('Y-m-d H:i:s');
        $webhook->save();

        foreach ($this->eventsOf($webhook) as $event)
            $this->events->triggerFromWebhook($webhook->workspace, $webhook->id, $webhook->name, $event, $payload);

        return $call;
    }

    /**
     * @return Bunch<int,WebhookCall> Newest first, by pages
     */
    public function calls(Webhook $webhook, ?int $before = null): Bunch
    {
        $query = WebhookCall::select()->where('webhook', $webhook->id);
        if (null !== $before)
            $query->where('id', $before, '<');

        return $query
            ->order('id', 'DESC')
            ->limit(self::PAGE_SIZE)
            ->toBunch();
    }

    /**
     * @return Bunch<int,WebhookCall> The calls refused by the webhooks of a workspace, newest first, with their webhook
     */
    public function refusedCalls(int $workspace, int $limit): Bunch
    {
        $webhooks = Webhook::select()
            ->where('workspace', $workspace)
            ->toBunch()
            ->map(fn (Webhook $webhook) => $webhook->id);

        return WebhookCall::select(['_webhook'])
            ->whereIn('webhook', $webhooks)
            ->where('status', self::CALL_ACCEPTED, '<>')
            ->order('id', 'DESC')
            ->limit($limit)
            ->toBunch();
    }

    /**
     * The secret as is in `X-Tower-Secret`, or a signature of the body made with it. An unsecured
     * webhook accepts any call to its address.
     */
    private function isAuthenticated(Webhook $webhook, Request $request, string $body): bool
    {
        if (!$webhook->secured)
            return true;

        $secret = decrypt($webhook->secret);

        $given = $request->getHeader(self::HEADER_SECRET);
        if (null !== $given)
            return hash_equals($secret, trim($given));

        foreach (self::HEADERS_SIGNATURE as $header) {
            $signature = $request->getHeader($header);
            if (null !== $signature)
                return hash_equals('sha256='.hash_hmac('sha256', $body, $secret), strtolower(trim($signature)));
        }

        return false;
    }

    /**
     * @return ?string A JSON object, `{}` for an empty body ; null when the body is anything else or too big
     */
    private function payloadOf(string $body): ?string
    {
        if ('' === trim($body))
            return '{}';

        if (strlen($body) > self::MAX_PAYLOAD_BYTES)
            return null;

        // Kept as text : decoding then encoding it again would turn an empty object into an empty list
        return json_decode($body) instanceof \stdClass ? $body : null;
    }

    private function trace(Webhook $webhook, Request $request, string $status, ?string $body = null): WebhookCall
    {
        $userAgent = $request->getHeader('User-Agent');

        $call = (new WebhookCall([
            'webhook' => $webhook->id,
            'status' => $status,
            'ip' => $request->getIp(),
            'user_agent' => null === $userAgent || '' === $userAgent ? null : mb_substr(mb_scrub($userAgent, 'UTF-8'), 0, self::MAX_USER_AGENT_LENGTH),
            'payload' => null === $body || '' === $body ? null : mb_scrub(mb_strcut($body, 0, self::MAX_PAYLOAD_BYTES, 'UTF-8'), 'UTF-8'),
            'created_at' => date('Y-m-d H:i:s'),
        ]))->save();

        // An accepted call signals through the events it triggers
        if (self::CALL_ACCEPTED !== $status)
            $this->signals->send($webhook->workspace, WorkspaceSignals::WEBHOOK_REFUSED, ['webhook' => $webhook->id]);

        return $call;
    }

    private function encodeEvents(array $events): string
    {
        return json_encode(array_values(array_unique($events)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function newSecret(): string
    {
        return 'whsec_'.bin2hex(random_bytes(32));
    }
}
