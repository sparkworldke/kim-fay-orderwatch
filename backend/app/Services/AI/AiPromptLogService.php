<?php

namespace App\Services\AI;

use App\Models\AiPromptLog;
use Illuminate\Support\Facades\Auth;

class AiPromptLogService
{
    public function log(array $data): AiPromptLog
    {
        $user = Auth::user();

        return AiPromptLog::create([
            'user_id'          => $user?->id,
            'user_role'        => $user?->role ?? null,
            'prompt'           => $data['prompt'],
            'intent'           => $data['intent'] ?? null,
            'domains'          => $data['domains'] ?? null,
            'formulas_used'    => $data['formulas_used'] ?? null,
            'db_query_scope'   => $data['db_query_scope'] ?? null,
            'ai_message'       => $data['ai_message'] ?? null,
            'cards_returned'   => $data['cards'] ?? null,
            'sources'          => $data['sources'] ?? null,
            'provider'         => $data['provider'] ?? null,
            'response_time_ms' => $data['response_time_ms'] ?? null,
            'status'           => $data['status'] ?? 'success',
            'error_message'    => $data['error_message'] ?? null,
        ]);
    }
}
