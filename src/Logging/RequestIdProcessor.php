<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Adds `extra.request_id` to every Monolog record.
 *
 * Registered with #[AsMonologProcessor], which MonologBundle turns into the
 * `monolog.processor` tag at compile time — so it applies to every channel and
 * handler. It is deliberately not attached per-handler: a processor wired by
 * hand is one a future handler will forget, and that failure is silent. The log
 * line still exists; it just cannot be correlated with anything.
 *
 * WHY A PROCESSOR RATHER THAN A LOGGER DECORATOR (GUIDING-LIGHT §8.5):
 * this does not change what is logged or where — it only adds one key to the
 * record's `extra` map. That means it composes with the existing JSON formatter
 * and with `fingers_crossed`, and it stays out of the way of the message
 * itself. The aggregation query later is `json.extra.request_id = '…'`.
 *
 * Note the signature: Monolog 3 passes and expects a LogRecord OBJECT, not the
 * array that Monolog 2 used. The `readonly` properties mean the record must be
 * rebuilt rather than mutated.
 */
#[AsMonologProcessor]
final class RequestIdProcessor
{
    public function __construct(
        private readonly RequestId $requestId,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $id = $this->requestId->get();

        // Console commands and boot-time messages have no request. Omitting the
        // key is correct — inventing one would make request-scoped lines
        // indistinguishable from background ones.
        if (null === $id) {
            return $record;
        }

        $record->extra['request_id'] = $id;

        return $record;
    }
}
