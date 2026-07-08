<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailImportConfig extends Model
{
    public const MATCH_MODE_EXACT = 'exact';
    public const MATCH_MODE_WILDCARD = 'wildcard';
    public const MATCH_MODE_REGEX = 'regex';

    public const APPROVAL_PENDING = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';

    protected $fillable = [
        'sender_pattern',
        'match_mode',
        'is_wildcard',
        'display_name',
        'customer_id',
        'branch_name',
        'branch_tag_pattern',
        'customer_class',
        'po_patterns',
        'po_extraction_source',
        'ai_fallback_enabled',
        'is_active',
        'approval_status',
        'created_by',
        'approved_by',
        'approved_at',
        'last_matched_at',
        'last_imported_at',
        'auto_deactivated_at',
        'guardrail_metadata',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_wildcard'         => 'boolean',
            'ai_fallback_enabled' => 'boolean',
            'is_active'           => 'boolean',
            'po_patterns'         => 'array',
            'guardrail_metadata'  => 'array',
            'approved_at'         => 'datetime',
            'last_matched_at'     => 'datetime',
            'last_imported_at'    => 'datetime',
            'auto_deactivated_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(AcumaticaCustomer::class, 'customer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_APPROVED;
    }

    public function requiresDualApproval(): bool
    {
        return $this->match_mode === self::MATCH_MODE_EXACT && ! $this->is_wildcard;
    }

    /**
     * Test whether a raw sender email matches this config's pattern.
     */
    public function matchesSender(string $senderEmail): bool
    {
        return self::senderMatchesPattern(
            $senderEmail,
            $this->sender_pattern,
            $this->match_mode ?: ($this->is_wildcard ? self::MATCH_MODE_WILDCARD : self::MATCH_MODE_EXACT),
        );
    }

    /**
     * Static helper — usable without model instantiation.
     */
    public static function senderMatchesPattern(string $senderEmail, string $pattern, string $matchMode): bool
    {
        $senderEmail = strtolower(trim($senderEmail));
        $pattern     = strtolower(trim($pattern));

        if ($matchMode === self::MATCH_MODE_EXACT) {
            return $senderEmail === $pattern;
        }

        if ($matchMode === self::MATCH_MODE_WILDCARD && str_starts_with($pattern, '*@')) {
            $domain = substr($pattern, 2); // strip '*@'
            $atPos = strrpos($senderEmail, '@');
            if ($atPos === false) return false;
            $senderDomain = substr($senderEmail, $atPos + 1);
            return $senderDomain === $domain || str_ends_with($senderDomain, '.' . $domain);
        }

        if ($matchMode === self::MATCH_MODE_WILDCARD) {
            $regex = '/^' . str_replace(['.', '*'], ['\.', '.*'], $pattern) . '$/i';
            return (bool) preg_match($regex, $senderEmail);
        }

        if ($matchMode === self::MATCH_MODE_REGEX) {
            return @preg_match($pattern, $senderEmail) === 1;
        }

        return false;
    }

    /**
     * Find the first active config that matches a sender address.
     */
    public static function findForSender(string $senderEmail): ?self
    {
        static::autoDeactivateDormantExactConfigs();

        return self::query()
            ->where('is_active', true)
            ->where('approval_status', self::APPROVAL_APPROVED)
            ->get()
            ->sortBy(fn (self $cfg) => match ($cfg->match_mode ?: ($cfg->is_wildcard ? self::MATCH_MODE_WILDCARD : self::MATCH_MODE_EXACT)) {
                self::MATCH_MODE_EXACT => 0,
                self::MATCH_MODE_WILDCARD => 1,
                self::MATCH_MODE_REGEX => 2,
                default => 3,
            })
            ->first(fn (self $cfg) => $cfg->matchesSender($senderEmail));
    }

    public static function isSafeRegexPattern(string $pattern): bool
    {
        $trimmed = trim($pattern);

        if ($trimmed === '' || @preg_match($trimmed, 'test@example.com') === false) {
            return false;
        }

        $unsafeFragments = [
            '/.+@.+/',
            '/.*@.*/',
            '@.+',
            '@.*',
        ];

        foreach ($unsafeFragments as $fragment) {
            if (Str::contains($trimmed, $fragment)) {
                return false;
            }
        }

        return Str::contains($trimmed, ['@chandara', '@chandara-supermarket', '\\.com', '\\.co\\.ke']);
    }

    public function extractBranchTag(string $senderEmail): ?string
    {
        $pattern = trim((string) $this->branch_tag_pattern);
        if ($pattern === '') {
            return $this->branch_name;
        }

        if (@preg_match($pattern, $senderEmail, $matches) !== 1) {
            return $this->branch_name;
        }

        foreach (array_slice($matches, 1) as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $this->branch_name;
    }

    public static function autoDeactivateDormantExactConfigs(): int
    {
        return static::query()
            ->where('is_active', true)
            ->where('match_mode', self::MATCH_MODE_EXACT)
            ->whereNotNull('last_imported_at')
            ->where('last_imported_at', '<', now()->subDays(90))
            ->update([
                'is_active' => false,
                'auto_deactivated_at' => now(),
            ]);
    }
}
