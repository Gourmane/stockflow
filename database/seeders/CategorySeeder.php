<?php

namespace Database\Seeders;

use App\Models\Category;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Category::firstOrCreate(['name' => 'Ordinateurs']);
        Category::firstOrCreate(['name' => 'Imprimantes']);
        Category::firstOrCreate(['name' => 'Claviers']);
        Category::firstOrCreate(['name' => 'Souris']);
        Category::firstOrCreate(['name' => 'Câbles']);
        Category::firstOrCreate(['name' => 'Accessoires']);
    }
}
