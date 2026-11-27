<?php

namespace App\Domain\AccessKey\Connector;

use App\Domain\AccessKey\AccessKeyService;
use App\Domain\Event\EventService;
use Cube\Web\Http\Rules\Param;
use Cube\Web\Http\Rules\Rule;

/**
 * The handler rules of a configuration, as the front edits them : a list, so that their order and
 * their patterns survive JSON (a pattern such as `0` would turn a PHP object into a list).
 *
 * Written into the downloaded configuration in the format of the connector (see `toConnector()`), and
 * validated with the same constraints as its `parse_chain`, so that the connector never refuses them.
 *
 * @phpstan-type Pair array{key: string, value: string}
 * @phpstan-type HandlerRule array{
 *     pattern: string,
 *     run: list<string>,
 *     response: null|array{mode: 'text', text: string}|array{mode: 'fields', fields: list<Pair>},
 *     next: list<array{type: string, payload: list<Pair>}>
 * }
 */
class HandlerRules
{
    public const MAX_RULES = 50;
    public const MAX_COMMANDS = 10;
    public const MAX_COMMAND_LENGTH = 1000;
    public const MAX_TEXT_LENGTH = 4000;
    public const MAX_PAIRS = 20;
    public const MAX_KEY_LENGTH = 100;
    public const MAX_VALUE_LENGTH = 1000;

    /** `{payload.title}` : replaced by the connector */
    private const PLACEHOLDER = '/\{[A-Za-z0-9_.]+\}/';
    private const EVENT_TYPE = '/^[a-z0-9_-]+(\.[a-z0-9_-]+)*$/';

    public static function rule(): Rule
    {
        return Param::array(Param::object([
            'pattern' => Param::string()->withValueCondition(
                fn ($pattern) => 1 === preg_match(AccessKeyService::LISTEN_PATTERN, (string) $pattern),
                '{key} must be an event pattern of lowercase letters, digits, dots, dashes, underscores, * and ?'
            ),
            'run' => Param::array(self::command())->withValueCondition(
                fn ($run) => is_array($run) && count($run) >= 1 && count($run) <= self::MAX_COMMANDS,
                '{key} must list between 1 and '.self::MAX_COMMANDS.' commands'
            ),
            'response' => Param::object([
                'mode' => Param::string()->inArray(['text', 'fields']),
                'text' => Param::string(trim: false)->withValueCondition(
                    fn ($text) => mb_strlen((string) $text) <= self::MAX_TEXT_LENGTH,
                    '{key} must contain '.self::MAX_TEXT_LENGTH.' characters at most'
                )->optional(),
                'fields' => self::pairs()->optional(),
            ], nullable: true)->withValueCondition(
                fn ($response) => match ($response['mode'] ?? null) {
                    'text' => '' !== trim((string) ($response['text'] ?? '')),
                    'fields' => [] !== ($response['fields'] ?? []),
                    default => false,
                },
                '{key} must give a text, or at least one field'
            )->optional(),
            'next' => Param::array(Param::object([
                'type' => Param::string()->withValueCondition(
                    fn ($type) => self::isEventType((string) $type),
                    '{key} must be lowercase words separated by dots, 100 characters at most'
                ),
                'payload' => self::pairs()->optional(),
            ]))->withValueCondition(
                fn ($next) => is_array($next) && count($next) <= EventService::MAX_NEXT_EVENTS,
                '{key} lists '.EventService::MAX_NEXT_EVENTS.' events at most'
            )->optional(),
        ]))->withValueCondition(
            fn ($rules) => is_array($rules)
                && count($rules) <= self::MAX_RULES
                && count($rules) === count(array_unique(array_column($rules, 'pattern'))),
            '{key} must list '.self::MAX_RULES.' rules at most, each with its own pattern'
        );
    }

    /**
     * A type with placeholders (`{event}.done`) is checked as the connector would send it, with a word
     * in place of each placeholder.
     */
    public static function isEventType(string $type): bool
    {
        return strlen($type) <= 100 && 1 === preg_match(self::EVENT_TYPE, (string) preg_replace(self::PLACEHOLDER, 'x', $type));
    }

    /**
     * @param list<array<string,mixed>> $rules As validated by `rule()`
     *
     * @return list<HandlerRule> Every key present, in a stable order
     */
    public static function normalize(array $rules): array
    {
        return array_map(fn (array $rule) => [
            'pattern' => $rule['pattern'],
            'run' => array_values($rule['run']),
            'response' => match ($rule['response']['mode'] ?? null) {
                'text' => ['mode' => 'text', 'text' => $rule['response']['text']],
                'fields' => ['mode' => 'fields', 'fields' => self::normalizePairs($rule['response']['fields'])],
                default => null,
            },
            'next' => array_map(fn (array $event) => [
                'type' => $event['type'],
                'payload' => self::normalizePairs($event['payload'] ?? []),
            ], array_values($rule['next'] ?? [])),
        ], array_values($rules));
    }

    /**
     * @param list<HandlerRule> $rules
     *
     * @return ?\stdClass The `handlers` section of the connector, null without rules : the connector refuses an empty one
     */
    public static function toConnector(array $rules): ?\stdClass
    {
        if (!$rules)
            return null;

        $handlers = new \stdClass();
        foreach ($rules as $rule) {
            $handler = ['run' => 1 === count($rule['run']) ? $rule['run'][0] : $rule['run']];

            // Without a response, the connector answers with the output of the commands
            if ($rule['response'])
                $handler['response'] = 'text' === $rule['response']['mode'] ? $rule['response']['text'] : self::toObject($rule['response']['fields']);

            if ($rule['next'])
                $handler['next'] = array_map(fn (array $event) => ['type' => $event['type'], 'payload' => self::toObject($event['payload'])], $rule['next']);

            $handlers->{$rule['pattern']} = $handler;
        }

        return $handlers;
    }

    /**
     * @return list<HandlerRule>
     */
    public static function decode(?string $stored): array
    {
        $rules = json_decode((string) $stored, true);

        return is_array($rules) ? $rules : [];
    }

    public static function encode(array $rules): string
    {
        return json_encode(array_values($rules), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function command(): Param
    {
        return Param::string()->withValueCondition(
            fn ($command) => '' !== (string) $command && mb_strlen((string) $command) <= self::MAX_COMMAND_LENGTH && self::isSplittable((string) $command),
            '{key} must contain between 1 and '.self::MAX_COMMAND_LENGTH.' characters, with every quote closed'
        );
    }

    /**
     * Whether Python's `shlex.split()` accepts the command : the connector refuses a configuration whose
     * quotes are left open, or that ends with a lone backslash.
     */
    public static function isSplittable(string $command): bool
    {
        $quote = null;
        $length = strlen($command);

        for ($i = 0; $i < $length; ++$i) {
            $char = $command[$i];

            if ("'" === $quote) {
                $quote = "'" === $char ? null : $quote;
                continue;
            }

            if ('\\' === $char) {
                if (++$i >= $length)
                    return false;
                continue;
            }

            if ('"' === $char || ("'" === $char && null === $quote))
                $quote = $quote === $char ? null : ($quote ?? $char);
        }

        return null === $quote;
    }

    private static function pairs(): Rule
    {
        return Param::array(Param::object([
            'key' => Param::string()->withValueCondition(
                fn ($key) => '' !== (string) $key && mb_strlen((string) $key) <= self::MAX_KEY_LENGTH,
                '{key} must contain between 1 and '.self::MAX_KEY_LENGTH.' characters'
            ),
            'value' => Param::string(trim: false)->withValueCondition(
                fn ($value) => mb_strlen((string) $value) <= self::MAX_VALUE_LENGTH,
                '{key} must contain '.self::MAX_VALUE_LENGTH.' characters at most'
            ),
        ]))->withValueCondition(
            fn ($pairs) => is_array($pairs)
                && count($pairs) <= self::MAX_PAIRS
                && count($pairs) === count(array_unique(array_column($pairs, 'key'))),
            '{key} must list '.self::MAX_PAIRS.' fields at most, each with its own name'
        );
    }

    /**
     * @param list<array{key: mixed, value: mixed}> $pairs
     *
     * @return list<Pair>
     */
    private static function normalizePairs(array $pairs): array
    {
        return array_map(fn (array $pair) => ['key' => (string) $pair['key'], 'value' => (string) $pair['value']], array_values($pairs));
    }

    /**
     * @param list<Pair> $pairs
     */
    private static function toObject(array $pairs): \stdClass
    {
        $object = new \stdClass();
        foreach ($pairs as $pair)
            $object->{$pair['key']} = $pair['value'];

        return $object;
    }
}
