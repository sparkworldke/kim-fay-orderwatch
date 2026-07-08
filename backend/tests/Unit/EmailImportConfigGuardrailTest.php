<?php

namespace Tests\Unit;

use App\Models\EmailImportConfig;
use Tests\TestCase;

class EmailImportConfigGuardrailTest extends TestCase
{
    public function test_safe_regex_requires_scoped_chandara_domain_pattern(): void
    {
        $this->assertTrue(EmailImportConfig::isSafeRegexPattern('/^branch-\d+@chandara\.com$/i'));
        $this->assertFalse(EmailImportConfig::isSafeRegexPattern('/.+@.+/'));
    }

    public function test_branch_tag_can_be_extracted_from_sender_address(): void
    {
        $config = new EmailImportConfig([
            'branch_name' => 'Chandara',
            'branch_tag_pattern' => '/^([a-z0-9-]+)@chandara-supermarket\.com$/i',
        ]);

        $this->assertSame('branch-12', $config->extractBranchTag('branch-12@chandara-supermarket.com'));
    }

    public function test_exact_matches_are_prioritized_over_wildcards(): void
    {
        $exact = new EmailImportConfig([
            'sender_pattern' => 'orders@chandara.com',
            'match_mode' => EmailImportConfig::MATCH_MODE_EXACT,
            'is_active' => true,
            'approval_status' => EmailImportConfig::APPROVAL_APPROVED,
        ]);

        $wildcard = new EmailImportConfig([
            'sender_pattern' => '*@chandara.com',
            'match_mode' => EmailImportConfig::MATCH_MODE_WILDCARD,
            'is_active' => true,
            'approval_status' => EmailImportConfig::APPROVAL_APPROVED,
        ]);

        $this->assertTrue($exact->matchesSender('orders@chandara.com'));
        $this->assertTrue($wildcard->matchesSender('orders@chandara.com'));
        $this->assertFalse($exact->matchesSender('branch@chandara.com'));
    }
}
