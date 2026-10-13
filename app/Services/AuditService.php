<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

final class AuditService
{
    public function record(string $action, string $subject, ?int $id = null, array $metadata = []): void
    {
        AuditLog::create(['actor_id' => Auth::id(), 'action' => $action, 'subject' => $subject, 'subject_id' => $id, 'metadata' => $metadata]);
    }
}
