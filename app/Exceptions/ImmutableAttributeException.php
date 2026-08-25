<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A write to an attribute that something outside this system keys on.
 *
 * This is a programming error, not a member-facing one — there is no request that
 * should ever produce it — so it is a LogicException and it is not rendered.
 */
class ImmutableAttributeException extends LogicException
{
    public static function for(Model $model, string $attribute): self
    {
        return new self(sprintf(
            '%s::$%s cannot be changed once the record exists.',
            $model::class,
            $attribute,
        ));
    }
}
