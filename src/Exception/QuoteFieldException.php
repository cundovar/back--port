<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A submitted field the server refuses. Carries the field name so the API can
 * tell the browser which input to flag, instead of a bare 400 the client can
 * only report as "l'envoi a échoué".
 */
class QuoteFieldException extends \DomainException
{
    public function __construct(private readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public function getField(): string
    {
        return $this->field;
    }
}
