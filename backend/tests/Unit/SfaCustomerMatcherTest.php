<?php
namespace Tests\Unit;
use App\Services\Sfa\SfaCustomerMatcher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
class SfaCustomerMatcherTest extends TestCase {
    #[Test] public function it_normalizes_customer_names_for_safe_comparison(): void { $matcher=app(SfaCustomerMatcher::class); $this->assertSame('BISHARA WHOLESALERS LTD',$matcher->normalizeName(' Bishara Wholesalers (Ltd.) ')); }
}
