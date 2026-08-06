<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAttributionAudit extends Model
{
    protected $fillable = [
        'customer_acumatica_id',
        'prior_user_id',
        'new_user_id',
        'winning_source',
        'winning_rule_type',
        'assignment_rule_id',
        'competing_candidates',
        'resolution_reason',
        'actor_type',
        'actor_user_id',
        'source_batch_id',
    ];

    protected $casts = [
        'competing_candidates' => 'array',
    ];

    public function priorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prior_user_id');
    }

    public function newUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CustomerAssignmentRule::class, 'assignment_rule_id');
    }
}
