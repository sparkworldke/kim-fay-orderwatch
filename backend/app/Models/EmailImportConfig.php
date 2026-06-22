<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailImportConfig extends Model
{
    protected $fillable = [
        'sender_pattern',
        'is_wildcard',
        'display_name',
        'customer_class',
        'po_patterns',
        'po_extraction_source',
        'ai_fallback_enabled',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_wildcard'         => 'boolean',
            'ai_fallback_enabled' => 'boolean',
            'is_active'           => 'boolean',
            'po_patterns'         => 'array',
        ];
    }

    /**
     * Test whether a raw sender email matches this config's pattern.
     */
    public function matchesSender(string $senderEmail): bool
    {
        return self::senderMatchesPattern($senderEmail, $this->sender_pattern, $this->is_wildcard);
    }

    /**
     * Static helper — usable without model instantiation.
     */
    public static function senderMatchesPattern(string $senderEmail, string $pattern, bool $isWildcard): bool
    {
        $senderEmail = strtolower(trim($senderEmail));
        $pattern     = strtolower(trim($pattern));

        if (! $isWildcard) {
            return $senderEmail === $pattern;
        }

        // Wildcard: *@domain.com → match any @domain.com
        if (str_starts_with($pattern, '*@')) {
            $domain = substr($pattern, 2); // strip '*@'
            // Extract the domain part from the sender email
            $atPos = strrpos($senderEmail, '@');
            if ($atPos === false) return false;
            $senderDomain = substr($senderEmail, $atPos + 1);
            // Match exact domain OR any subdomain (e.g. joska.quickmart.co.ke → quickmart.co.ke)
            return $senderDomain === $domain || str_ends_with($senderDomain, '.' . $domain);
        }

        // Generic glob-style wildcard
        $regex = '/^' . str_replace(['.', '*'], ['\.', '.*'], $pattern) . '$/i';
        return (bool) preg_match($regex, $senderEmail);
    }

    /**
     * Find the first active config that matches a sender address.
     */
    public static function findForSender(string $senderEmail): ?self
    {
        return self::where('is_active', true)->get()
            ->first(fn (self $cfg) => $cfg->matchesSender($senderEmail));
    }
}
