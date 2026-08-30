<?php

namespace App\Services\Agents;

use JsonSerializable;
use Stringable;

/**
 * One error or warning raised by an execution agent.
 *
 * This type exists because `ExecutionResult::$errors` and `ValidationResult::$errors`
 * used to hold a `string[]|array[]` union — plain strings when built through a
 * constructor, `['code' => ..., 'message' => ...]` arrays when built through
 * `addError()`. Consumers could not tell which they had, so `DeploymentService`
 * carried two different normalisers forty-five lines apart and stored a raw
 * JSON blob in `strategies.deployment_error` for Google/Facebook failures while
 * storing a clean sentence for Microsoft/LinkedIn ones.
 *
 * Now there is one shape. It stringifies to the human-readable message, so
 * `implode()` over a list of these produces the sentence a customer should see,
 * and it JSON-serialises to the keyed array the JSON columns already store.
 */
final class AgentIssue implements JsonSerializable, Stringable
{
    /**
     * Code used for issues that carry no machine-readable classification.
     */
    public const GENERAL = 'general';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {}

    /**
     * Build an issue from the two call shapes used across the agents.
     *
     * Executors under `Agents/Google/` raise free-text warnings with a single
     * argument ("Failed to add keyword: ..."); the prerequisite validators raise
     * coded ones with two ("no_pixel", "No Facebook Pixel configured"). Both are
     * legitimate — a machine-readable code is only worth inventing when
     * something downstream branches on it — so both are accepted here rather
     * than forcing a fake code onto every incidental warning.
     */
    public static function make(string $codeOrMessage, ?string $message = null): self
    {
        if ($message !== null && $message !== '') {
            return new self($codeOrMessage, $message);
        }

        return new self(self::GENERAL, $codeOrMessage);
    }

    /**
     * Normalise anything an older call site might still hand us into an issue.
     *
     * Accepts an AgentIssue, a `['code' => ..., 'message' => ...]` array (the
     * shape read back out of the JSON columns), or a bare string.
     */
    public static function from(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_array($value)) {
            return new self(
                (string) ($value['code'] ?? self::GENERAL),
                (string) ($value['message'] ?? json_encode($value)),
            );
        }

        if ($value instanceof Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return new self(self::GENERAL, (string) $value);
        }

        return new self(self::GENERAL, is_scalar($value) ? (string) $value : json_encode($value));
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<self>
     */
    public static function list(iterable $values): array
    {
        $issues = [];

        foreach ($values as $value) {
            $issues[] = self::from($value);
        }

        return $issues;
    }

    /**
     * Join a list of issues into one human-readable sentence.
     *
     * @param  iterable<mixed>  $issues
     */
    public static function toSentence(iterable $issues, string $separator = '; '): string
    {
        return implode($separator, array_map(
            static fn (self $issue): string => $issue->message,
            self::list($issues),
        ));
    }

    /**
     * @return array{code: string, message: string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message];
    }

    /**
     * @return array{code: string, message: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->message;
    }
}
