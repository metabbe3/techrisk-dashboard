<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            Category::TYPE_BUSINESS_CATEGORY => [
                'Fraud', 'Operational', 'IT Security', 'Compliance',
                'System Failure', 'Human Error', 'External Attack', 'Process Gap',
            ],
            Category::TYPE_ROOT_CAUSE_CATEGORY => [
                'Human Error', 'System Bug', 'Process Failure', 'Third Party',
                'Configuration Error', 'Security Breach', 'Infrastructure', 'Design Flaw',
            ],
            Category::TYPE_RESPONSIBLE_TEAM => [
                'Engineering', 'Operations', 'Security', 'IT Infrastructure',
                'Product', 'QA', 'Third Party', 'Management',
            ],
        ];

        foreach ($categories as $type => $names) {
            foreach ($names as $name) {
                Category::firstOrCreate(['type' => $type, 'name' => $name]);
            }
        }
    }
}
