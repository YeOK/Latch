<?php

declare(strict_types=1);

namespace Latch\Support;

use Latch\Core\Application;
use Latch\Core\Response;

/**
 * JSON-first responses for in-page staff actions (staff-actions.js, mod-tools.js).
 * Success messages stay in the AJAX payload — they are not stored as session flash,
 * so navigating to the forum after an admin trash purge does not show a green banner.
 */
trait StaffActionResponder
{
    abstract protected function staffApp(): Application;

    private function wantsJson(): bool
    {
        $request = $this->staffApp()->request();
        if ($request->header('X-Requested-With') === 'XMLHttpRequest') {
            return true;
        }

        return str_contains($request->header('Accept'), 'application/json');
    }

    /**
     * @param array<string, mixed> $jsonData
     */
    private function finishStaffAction(bool $success, string $message, string $redirectUrl, array $jsonData = []): void
    {
        if ($this->wantsJson()) {
            Response::json(array_merge([
                'ok' => $success,
                'message' => $message,
                'redirect' => $redirectUrl,
            ], $jsonData), $success ? 200 : 400);
        }

        if (!$success) {
            $this->staffApp()->session()->flash('error', $message);
        }

        Response::redirect($redirectUrl);
    }
}