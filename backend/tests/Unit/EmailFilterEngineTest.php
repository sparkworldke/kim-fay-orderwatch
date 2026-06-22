<?php

namespace Tests\Unit;

use App\Models\EmailFilter;
use App\Services\Email\EmailFilterEngine;
use Tests\TestCase;

class EmailFilterEngineTest extends TestCase
{
    private EmailFilterEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new EmailFilterEngine();
    }

    private function makeFilter(string $type, string $value, bool $legacy = false): EmailFilter
    {
        if ($legacy) {
            return new EmailFilter(['type' => $type, 'value' => $value]);
        }

        return new EmailFilter([
            'conditions' => [[
                'type' => $type,
                'value' => $value,
            ]],
        ]);
    }

    public function test_sender_email_matches_exact_address(): void
    {
        $filter = $this->makeFilter('sender_email', 'alice@example.com');

        $this->assertTrue($this->engine->matchesFilter(['from_email' => 'alice@example.com'], $filter));
    }

    public function test_sender_email_does_not_match_different_address(): void
    {
        $filter = $this->makeFilter('sender_email', 'alice@example.com');

        $this->assertFalse($this->engine->matchesFilter(['from_email' => 'bob@example.com'], $filter));
    }

    public function test_sender_email_match_is_case_insensitive(): void
    {
        $filter = $this->makeFilter('sender_email', 'Alice@Example.COM');

        $this->assertTrue($this->engine->matchesFilter(['from_email' => 'alice@example.com'], $filter));
    }

    public function test_sender_domain_matches_emails_from_that_domain(): void
    {
        $filter = $this->makeFilter('sender_domain', 'gmail.com');

        $this->assertTrue($this->engine->matchesFilter(['from_email' => 'user@gmail.com'], $filter));
    }

    public function test_sender_domain_does_not_match_different_domain(): void
    {
        $filter = $this->makeFilter('sender_domain', 'gmail.com');

        $this->assertFalse($this->engine->matchesFilter(['from_email' => 'user@yahoo.com'], $filter));
    }

    public function test_sender_domain_does_not_partially_match_dissimilar_domain(): void
    {
        // "evil.com" must not match "notevil.com" because we check for "@evil.com"
        $filter = $this->makeFilter('sender_domain', 'evil.com');

        $this->assertFalse($this->engine->matchesFilter(['from_email' => 'user@notevil.com'], $filter));
    }

    public function test_sender_domain_match_is_case_insensitive(): void
    {
        $filter = $this->makeFilter('sender_domain', 'GMAIL.COM');

        $this->assertTrue($this->engine->matchesFilter(['from_email' => 'user@gmail.com'], $filter));
    }

    public function test_subject_keyword_matches_when_keyword_is_present(): void
    {
        $filter = $this->makeFilter('subject_keyword', 'invoice');

        $this->assertTrue($this->engine->matchesFilter(['subject' => 'Invoice #1234'], $filter));
    }

    public function test_subject_keyword_does_not_match_when_keyword_absent(): void
    {
        $filter = $this->makeFilter('subject_keyword', 'invoice');

        $this->assertFalse($this->engine->matchesFilter(['subject' => 'Meeting notes for Q2'], $filter));
    }

    public function test_subject_keyword_match_is_case_insensitive(): void
    {
        $filter = $this->makeFilter('subject_keyword', 'ORDER');

        $this->assertTrue($this->engine->matchesFilter(['subject' => 'Your order has shipped'], $filter));
    }

    public function test_subject_keyword_matches_partial_word(): void
    {
        $filter = $this->makeFilter('subject_keyword', 'ship');

        $this->assertTrue($this->engine->matchesFilter(['subject' => 'Your shipment is on its way'], $filter));
    }

    public function test_unknown_filter_type_never_matches(): void
    {
        $filter = $this->makeFilter('unsupported_type', 'anything');

        $this->assertFalse($this->engine->matchesFilter(['from_email' => 'test@example.com', 'subject' => 'anything'], $filter));
    }

    public function test_missing_email_fields_do_not_cause_errors(): void
    {
        $filter = $this->makeFilter('sender_email', 'x@x.com');

        $this->assertFalse($this->engine->matchesFilter([], $filter));
    }

    public function test_sender_domain_handles_empty_from_email(): void
    {
        $filter = $this->makeFilter('sender_domain', 'gmail.com');

        $this->assertFalse($this->engine->matchesFilter(['from_email' => ''], $filter));
    }
}
