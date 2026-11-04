<?php

namespace App\Http\Requests\Concerns;

use App\Support\DigitNormalizer;

trait NormalizesDigits
{
    /**
     * مفاتيح الحقول التي تُحوَّل أرقامها للعربية→غربية قبل التحقق.
     *
     * @return list<string>
     */
    abstract protected function digitKeys(): array;

    protected function prepareForValidation(): void
    {
        $this->merge(DigitNormalizer::fields($this->all(), $this->digitKeys()));
    }
}
