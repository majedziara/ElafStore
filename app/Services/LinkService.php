<?php

namespace App\Services;

use App\Models\LinkToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LinkService
{
    public function issue(string $purpose, int $target, array $payload = [], int $minutes = 60): string
    {
        $raw = bin2hex(random_bytes(32));
        LinkToken::create(['token_hash' => hash('sha256', $raw), 'purpose' => $purpose, 'target_id' => $target,
            'payload' => $payload, 'expires_at' => now()->addMinutes($minutes)]);

        return $raw;
    }

    public function consume(string $raw, string $purpose, callable $action): mixed
    {
        return DB::transaction(function () use ($raw, $purpose, $action) {
            $link = LinkToken::where('token_hash', hash('sha256', $raw))->where('purpose', $purpose)->lockForUpdate()->first();
            if (! $link || $link->used_at || $link->expires_at->isPast()) {
                throw ValidationException::withMessages(['token' => 'This link is invalid or expired.']);
            }
            $result = $action($link);
            $link->update(['used_at' => now()]);

            return $result;
        }, 3);
    }
}
