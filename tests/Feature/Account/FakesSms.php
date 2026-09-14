<?php

namespace Tests\Feature\Account;

use App\Exceptions\SmsException;
use App\Services\SmsService;

/**
 * Replaces the SMS gateway with one that remembers the texts (Phase 7 tests).
 */
trait FakesSms
{
    protected function fakeSms(): object
    {
        $fake = new class extends SmsService
        {
            /** @var list<array{to: string, message: string}> */
            public array $sent = [];

            public bool $fail = false;

            public function available(): bool
            {
                return true;
            }

            public function send(string $to, string $message): void
            {
                if ($this->fail) {
                    throw new SmsException('The SMS could not be sent.');
                }
                $this->sent[] = ['to' => $to, 'message' => $message];
            }

            public function lastCode(): ?string
            {
                $last = end($this->sent);

                return $last && preg_match('/^(\d{6}) /', $last['message'], $match) ? $match[1] : null;
            }
        };

        $this->app->instance(SmsService::class, $fake);

        return $fake;
    }
}
