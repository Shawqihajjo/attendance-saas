<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Application;

/**
 * Failure text that is safe to store in the platform audit log and return to an operator.
 */
final class SafeFailure
{
    public static function reason(\Throwable $exception): string
    {
        for ($current = $exception; $current instanceof \Throwable; $current = $current->getPrevious()) {
            if ($current instanceof \PDOException || $current instanceof \Doctrine\DBAL\Exception) {
                return 'The operation could not be completed.';
            }
        }
        $message = preg_replace('/[\\x00-\\x1F\\x7F]/', '', $exception->getMessage()) ?? '';
        $lower = strtolower($message);
        foreach (['password', 'secret', 'token', 'ciphertext', 'mysql://', 'sqlite:', 'pdo', 'sqlstate', 'access denied'] as $part) {
            if (str_contains($lower, $part)) {
                return 'The operation could not be completed.';
            }
        }
        if ($message === '' || strlen($message) > 180) {
            return 'The operation could not be completed.';
        }

        return $message;
    }
}
