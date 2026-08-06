<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['slug' => 'mt_consumer_sales', 'name' => 'MT / Consumer Sales', 'segment' => 'Commercial', 'is_customer_facing' => true, 'sort_order' => 1],
            ['slug' => 'gt', 'name' => 'GT', 'segment' => 'Commercial', 'is_customer_facing' => true, 'sort_order' => 2],
            ['slug' => 'kp', 'name' => 'KP', 'segment' => 'Commercial', 'is_customer_facing' => true, 'sort_order' => 3],
            ['slug' => 'customer_service', 'name' => 'Customer Service', 'segment' => 'Commercial', 'is_customer_facing' => false, 'sort_order' => 4],
            ['slug' => 'marketing', 'name' => 'Marketing', 'segment' => 'Commercial', 'is_customer_facing' => false, 'sort_order' => 5],
            ['slug' => 'c_suite', 'name' => 'C-Suite', 'segment' => 'Executive', 'is_customer_facing' => false, 'sort_order' => 6],
            ['slug' => 'it', 'name' => 'IT', 'segment' => 'Operations', 'is_customer_facing' => false, 'sort_order' => 7],
            ['slug' => 'hr', 'name' => 'HR', 'segment' => 'Operations', 'is_customer_facing' => false, 'sort_order' => 8],
            ['slug' => 'dispatch', 'name' => 'Dispatch', 'segment' => 'Operations', 'is_customer_facing' => false, 'sort_order' => 9],
            ['slug' => 'stores', 'name' => 'Stores', 'segment' => 'Operations', 'is_customer_facing' => false, 'sort_order' => 10],
            ['slug' => 'production', 'name' => 'Production', 'segment' => 'Operations', 'is_customer_facing' => false, 'sort_order' => 11],
            ['slug' => 'partner_brands', 'name' => 'Partner Brands', 'segment' => 'Commercial', 'is_customer_facing' => false, 'sort_order' => 12],
            ['slug' => 'finance', 'name' => 'Finance', 'segment' => 'Operations', 'is_customer_facing' => false, 'sort_order' => 13],
            ['slug' => 'procurement', 'name' => 'Procurement', 'segment' => 'Operations', 'is_customer_facing' => false, 'sort_order' => 14],
        ];

        foreach ($departments as $dept) {
            Department::updateOrCreate(['slug' => $dept['slug']], $dept);
        }
    }
}
