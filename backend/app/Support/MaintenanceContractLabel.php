<?php

namespace App\Support;

/**
 * Swap-generated maintenance text stores a contract number.
 * Readers without contracts.view see the maintenance fact only.
 * The stored row is not rewritten.
 */
final class MaintenanceContractLabel
{
    public static function present(?string $text, bool $canSeeContracts): ?string
    {
        if ($text === null || $canSeeContracts) {
            return $text;
        }

        $stripped = preg_replace('/^(تبديل بسبب عطل)\s*—\s*CNT-\d+\s*$/u', '$1', $text);
        if (is_string($stripped) && $stripped !== $text) {
            return $stripped;
        }

        $stripped = preg_replace('/^(فحص عطل بعد التبديل)\s+من العقد\s+CNT-\d+\s*$/u', '$1', $text);

        return is_string($stripped) ? $stripped : $text;
    }
}
