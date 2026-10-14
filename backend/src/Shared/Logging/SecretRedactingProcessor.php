<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('monolog.processor')]
final class SecretRedactingProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: SecretRedactor::redact($record->message),
            context: $this->redactArray($record->context, 0),
            extra: $this->redactArray($record->extra, 0),
        );
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private function redactArray(array $values, int $depth): array
    {
        if ($depth > 4) {
            return $values;
        }

        $clean = [];
        foreach ($values as $key => $value) {
            $clean[$key] = $this->redactValue($value, $depth);
        }

        return $clean;
    }

    private function redactValue(mixed $value, int $depth): mixed
    {
        if (is_string($value)) {
            return SecretRedactor::redact($value);
        }
        if (is_array($value)) {
            return $this->redactArray($value, $depth + 1);
        }

        return $value;
    }
}
