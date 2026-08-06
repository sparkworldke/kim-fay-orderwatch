<?php

namespace Tests\Feature;

use App\Models\CustomerAssignmentBatchRow;
use App\Models\User;
use App\Services\Team\StaffPortfolioImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the July 2026 precedence engine against the REAL source workbooks
 * (excels/), seeding only the users the business-rule reps resolve to. This is
 * the acceptance-criteria test from docs/PRD-staff-identity-and-customer-portfolio-import.md
 * §4.6 — the two documented carve-outs are the whole point of this PRD.
 */
class StaffPortfolioImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private const STAFF_PATH = __DIR__.'/../../../excels/Staff_Email_Match_July_2026.xlsx';
    private const OUTLETS_PATH = __DIR__.'/../../../excels/MT Outlets by Rep.xlsx';
    private const CUSTOMERS_PATH = __DIR__.'/../../../excels/Customers 20260713.xlsx';

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_file(self::STAFF_PATH) || ! is_file(self::OUTLETS_PATH) || ! is_file(self::CUSTOMERS_PATH)) {
            $this->markTestSkipped('Source workbooks are not present in this environment.');
        }
    }

    /**
     * Real emails as they appear in the actual "Matched Staff" sheet — resolution goes
     * outlet-rep-name -> fuzzy-matched staff name -> that staff member's real email ->
     * User::where('email', ...), so a test user must carry the real email or it can never
     * resolve, regardless of rep_code. Lawrence Amukhono is deliberately excluded: per the
     * real HR data he's in the "Missing Email" sheet (no active account), so he correctly
     * cannot resolve today — that's a real data gap to flag, not a bug in this test.
     *
     * @return array<string, User> keyed by rep name
     */
    private function seedReps(): array
    {
        $names = [
            'Beryl Muga' => ['REPBERYL', 'muga.kac@kimfay.com'],
            'George Amenya' => ['REPGEORGE', 'gamenya@kimfay.com'],
            'Georgina Kiilu' => ['REPGEORGINA', 'georgina.kac@kimfay.com'],
            'Lucy Wanjiru' => ['REPLUCY', 'lucy.kac@kimfay.com'],
            'Jane Kuria' => ['REPJANE', 'jane.kac@kimfay.com'],
            'Kevin Werunga' => ['REPKEVIN', 'kelvin.werunga@kimfay.com'],
            'Zipporah Wangeci' => ['REPZIPPORAH', 'moderntrade.nrb@kimfay.com'],
            'Lilian Kimeu' => ['REPLILIAN', 'lilian.kimeu@kimfay.com'],
            'Dennis Mutwiri' => ['REPDENNIS', 'dennis.kac@kimfay.com'],
        ];

        $users = [];
        foreach ($names as $name => [$repCode, $email]) {
            $users[$name] = User::factory()->create([
                'name' => $name,
                'email' => $email,
                'rep_code' => $repCode,
                'is_active' => true,
            ]);
        }

        return $users;
    }

    private function resolvedUserIdFor(array $rows, string $customerId): ?int
    {
        foreach ($rows as $row) {
            if ($row->customer_acumatica_id === $customerId) {
                return $row->resolved_user_id;
            }
        }

        return null;
    }

    public function test_precedence_engine_resolves_the_two_documented_carveouts_correctly(): void
    {
        $users = $this->seedReps();

        $batch = app(StaffPortfolioImportService::class)->preview(
            self::STAFF_PATH,
            self::OUTLETS_PATH,
            self::CUSTOMERS_PATH,
        );

        $rows = CustomerAssignmentBatchRow::query()->where('batch_id', $batch->id)->get()->all();
        $this->assertNotEmpty($rows, 'Preview produced no rows at all — sheet/name resolution likely broken.');

        // Naivas-Thika: the PRD's flagship carve-out. Region is "Nairobi Key Accounts" for
        // these two outlets (not "Thika") — only the branch name carries the signal.
        $this->assertSame(
            $users['Lilian Kimeu']->id,
            $this->resolvedUserIdFor($rows, 'CUST101416'),
            'Naivasha Ananas Thika branch must resolve to Lilian Kimeu, not Lucy Wanjiru.',
        );
        $this->assertSame(
            $users['Lilian Kimeu']->id,
            $this->resolvedUserIdFor($rows, 'CUST101303'),
            'Naivasha Thika Town branch must resolve to Lilian Kimeu, not Lucy Wanjiru.',
        );

        // A non-Thika Naivas branch must still resolve to Lucy — the carve-out must not
        // swallow the whole main account.
        $this->assertSame(
            $users['Lucy Wanjiru']->id,
            $this->resolvedUserIdFor($rows, 'CUST101298'),
            'Non-Thika Naivas branch must still resolve to Lucy Wanjiru.',
        );

        // Magunas-Thika: same carve-out pattern, different account. Region is also
        // "Nairobi West" here — identical to the genuine Nairobi branches below — so this
        // only passes if the branch name (not Region) drives the decision.
        $this->assertSame(
            $users['Lilian Kimeu']->id,
            $this->resolvedUserIdFor($rows, 'CUST102375'),
            'Magunas Thika branch must resolve to Lilian Kimeu, not Dennis Mutwiri.',
        );

        // Every other Magunas branch is Dennis's ("all Magunas in Nairobi").
        $this->assertSame(
            $users['Dennis Mutwiri']->id,
            $this->resolvedUserIdFor($rows, 'CUST102842'),
            'Magunas Membley branch must resolve to Dennis Mutwiri.',
        );

        // Quick Mart is main-account-wide for Georgina regardless of region.
        $this->assertSame(
            $users['Georgina Kiilu']->id,
            $this->resolvedUserIdFor($rows, 'CUST101325'),
        );

        // No customer may resolve to two different servicing users in the same batch.
        $seen = [];
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey(
                $row->customer_acumatica_id,
                $seen,
                "Customer {$row->customer_acumatica_id} appears more than once in the batch.",
            );
            $seen[$row->customer_acumatica_id] = true;
        }
    }
}
