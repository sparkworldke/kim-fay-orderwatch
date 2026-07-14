<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['slug' => 'mt_consumer_sales', 'name' => 'MT / Consumer Sales', 'is_customer_facing' => true, 'sort_order' => 1],
            ['slug' => 'gt', 'name' => 'GT', 'is_customer_facing' => true, 'sort_order' => 2],
            ['slug' => 'kp', 'name' => 'KP', 'is_customer_facing' => true, 'sort_order' => 3],
            ['slug' => 'customer_service', 'name' => 'Customer Service', 'is_customer_facing' => false, 'sort_order' => 4],
            ['slug' => 'marketing', 'name' => 'Marketing', 'is_customer_facing' => false, 'sort_order' => 5],
            ['slug' => 'c_suite', 'name' => 'C-Suite', 'is_customer_facing' => false, 'sort_order' => 6],
            ['slug' => 'it', 'name' => 'IT', 'is_customer_facing' => false, 'sort_order' => 7],
            ['slug' => 'hr', 'name' => 'HR', 'is_customer_facing' => false, 'sort_order' => 8],
            ['slug' => 'dispatch', 'name' => 'Dispatch', 'is_customer_facing' => false, 'sort_order' => 9],
            ['slug' => 'stores', 'name' => 'Stores', 'is_customer_facing' => false, 'sort_order' => 10],
            ['slug' => 'production', 'name' => 'Production', 'is_customer_facing' => false, 'sort_order' => 11],
            ['slug' => 'partner_brands', 'name' => 'Partner Brands', 'is_customer_facing' => false, 'sort_order' => 12],
            ['slug' => 'finance', 'name' => 'Finance', 'is_customer_facing' => false, 'sort_order' => 13],
            ['slug' => 'procurement', 'name' => 'Procurement', 'is_customer_facing' => false, 'sort_order' => 14],
        ];

        foreach ($departments as $dept) {
            Department::updateOrCreate(['slug' => $dept['slug']], $dept);
        }
    }
}