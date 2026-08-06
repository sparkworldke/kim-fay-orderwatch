<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerPortfolioFoundationSeeder extends Seeder
{
    private const LEADERSHIP_EMAILS = [
        'commercialtechlead@kimfay.com',
        'cco@kimfay.com',
        'susan@kimfay.com',
        'hbains@kimfay.com',
        'rbains@kimfay.com',
    ];

    private const MT1_MAIN_ACCOUNTS = [
        'Majid Al Futtaim Hypermarkets Ltd',
        'Naivasha S.S.S Stores Limited',
        'Quick Mart Ltd',
    ];

    private const MT2_CUSTOMERS = [
        'Leestar Supermarket - Githurai',
        'Kassmatt Supermarket Ltd',
        'Kamindi Selfridges',
        'Kikuyu Selfridges (S/Markets) Ltd',
        'Eastleigh Mattresses Limited',
        'Jazaribu Retail Limited',
        'Khetia Drapers Ltd',
        "Maguna's Super Stores (K) Ltd",
        'Bidii Supermarket - Matuu',
        'Chandarana Supermarket',
        'Onn The Way Ltd',
        '99 Mart Limited',
        'Artcafe Coffee & Bakery Ltd - Market',
        'Om Shiv Impex Ltd',
        'Patel & Brothers Enterprise Limited',
        'Karen Provision Stores Ltd',
        'Viman Distributors Kenya Ltd',
        'Peekaboo Limited',
        'China Village Ltd',
        'Defence Forces Welfare Services (DEFWES)',
        'Powerstar Supermarket',
        'Powerstar Supermarket - Zimmerman',
        'Powerstar Supermarket - Mini',
        'Cleanshelf Supermarket',
        'Bidii Supermarket - Ruiru',
        'Powerstar Supermarket - Jambo',
        'Powerstar Supermarket Vasha Limited',
        'Powerstar Supermarket - Kikuyu',
        'Powerstar Supermarket Kinoo Limited',
        'Powerstar Supermarket - Kangari',
        'Powerstar Supermarket - Kitengela',
        'Dimple Supermarket Ltd',
        'Powerstar Supermarket - Hyper',
        'The Zoros Company',
        'Kiddie Kloset',
        'Powerstar Supermarket - Annex',
        'Powerstar Supermarket - Joska',
    ];

    public function run(): void
    {
        $this->ensureLawrenceExists();

        $kpPermission = Permission::query()->firstOrCreate(
            ['name' => 'kp.crm.access'],
            ['description' => 'Enter KP CRM when also in the approved KP cohort'],
        );
        Permission::query()->firstOrCreate(
            ['name' => 'team.manage_hierarchy'],
            ['description' => 'Preview and apply audited team/HOD migrations'],
        );
        $kpRole = Role::query()->firstOrCreate(
            ['name' => 'KP CRM Cohort'],
            ['description' => 'Explicit KP CRM product-area access; does not change sales ownership', 'is_system' => true],
        );
        DB::table('role_permissions')->updateOrInsert([
            'role_id' => $kpRole->id,
            'permission_id' => $kpPermission->id,
        ]);

        foreach (self::LEADERSHIP_EMAILS as $email) {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if (! $user) {
                $this->command?->warn("KP CRM leadership user not found: {$email}");
                continue;
            }
            DB::table('kp_crm_access_assignments')->updateOrInsert(
                ['user_id' => $user->id, 'access_basis' => 'launch_leadership'],
                [
                    'is_active' => true,
                    'approved_at' => now(),
                    'change_reason' => 'PRD launch leadership cohort',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            UserRole::query()->firstOrCreate(['user_id' => $user->id, 'role_id' => $kpRole->id]);
        }

        User::query()
            ->whereHas('departments', fn ($query) => $query->where('departments.slug', 'kp'))
            ->orWhereHas('department', fn ($query) => $query->where('departments.slug', 'kp'))
            ->pluck('id')
            ->each(fn ($userId) => UserRole::query()->firstOrCreate(['user_id' => $userId, 'role_id' => $kpRole->id]));

        foreach ([
            ['customer_category' => 'CSECOMM', 'sales_channel_code' => 'ECOMMERCE'],
            ['customer_category' => 'CSDIST', 'sales_channel_code' => 'GT'],
            ['customer_category' => 'CSWSALERS', 'sales_channel_code' => 'GT'],
        ] as $rule) {
            DB::table('sales_channel_category_rules')->updateOrInsert(
                $rule,
                [
                    'priority' => 100,
                    'is_active' => true,
                    'change_reason' => 'Approved initial customer-category mapping',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        $mt1CustomerIds = DB::table('acumatica_customers')
            ->where(function ($query) {
                $query->whereIn('main_account_name', self::MT1_MAIN_ACCOUNTS)
                    ->orWhereIn('name', self::MT1_MAIN_ACCOUNTS);
            })
            ->where('customer_class', 'not like', 'KP%')
            ->pluck('acumatica_id');

        $mt2CustomerIds = DB::table('acumatica_customers')
            ->whereIn('name', self::MT2_CUSTOMERS)
            ->where('customer_class', 'not like', 'KP%')
            ->pluck('acumatica_id');

        $mtOverrides = $mt1CustomerIds->map(fn ($id) => [$id, 'MT1'])
            ->concat($mt2CustomerIds->map(fn ($id) => [$id, 'MT2']));
        foreach ($mtOverrides as [$customerId, $channel]) {
            DB::table('customer_sales_channel_overrides')->updateOrInsert(
                ['customer_acumatica_id' => $customerId],
                [
                    'sales_channel_code' => $channel,
                    'is_active' => true,
                    'change_reason' => 'Approved Modern Trade PRD classification',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        app(\App\Services\Team\SalesChannelClassificationService::class)->classifyAll();
    }

    private function ensureLawrenceExists(): void
    {
        $department = Department::query()->where('slug', 'mt_consumer_sales')->first();
        $purity = User::query()->whereRaw('LOWER(email) = ?', ['moderntrade@kimfay.com'])->first();
        $salesConsultantRole = Role::query()->firstOrCreate(
            ['name' => 'Sales Consultant'],
            ['description' => 'Sales Consultant', 'is_system' => true],
        );

        $lawrence = User::query()->firstOrNew(['email' => 'moderntrade.exec@kimfay.com']);
        $isNew = ! $lawrence->exists;
        $lawrence->fill([
            'name' => 'Lawrence Amukhono Amukhono',
            'email' => 'moderntrade.exec@kimfay.com',
            'role' => 'Sales Consultant',
            'rep_code' => 'P272',
            'employee_number' => 'P272',
            'designation' => 'Modern Trade Executive',
            'division' => 'Consumer Sales',
            'department_id' => $department?->id,
            'department_role' => 'member',
            'org_level' => 'sales',
            'reports_to_user_id' => $purity?->id,
            'product_type_scope' => 'both',
            'data_scope_mode' => 'scoped',
            'is_consultant' => true,
            'is_active' => true,
            'email_verified_at' => $lawrence->email_verified_at ?? now(),
        ]);
        if ($isNew) {
            $lawrence->password = Str::random(48);
        }
        $lawrence->save();

        UserRole::query()->firstOrCreate([
            'user_id' => $lawrence->id,
            'role_id' => $salesConsultantRole->id,
        ]);

        if ($department !== null) {
            DB::table('department_user')->updateOrInsert(
                ['user_id' => $lawrence->id, 'department_id' => $department->id],
                [
                    'membership_role' => 'member',
                    'is_primary' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        } else {
            $this->command?->warn('MT / Consumer Sales department not found; Lawrence was created without a department assignment.');
        }

        if ($purity === null) {
            $this->command?->warn('Purity (moderntrade@kimfay.com) was not found; Lawrence was created without a reporting manager.');
        }

        $this->command?->info('Lawrence Amukhono verified: moderntrade.exec@kimfay.com / P272.');
    }
}
