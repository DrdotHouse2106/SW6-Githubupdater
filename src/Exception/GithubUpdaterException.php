<?php declare(strict_types=1);

namespace DhGithubUpdater\Exception;

/**
 * Fehler, deren Meldung bewusst für Administratoren formuliert ist und
 * gefahrlos an die Administration zurückgegeben werden darf.
 * Alle anderen Exceptions werden nur geloggt und generisch beantwortet.
 */
class GithubUpdaterException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 400,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
