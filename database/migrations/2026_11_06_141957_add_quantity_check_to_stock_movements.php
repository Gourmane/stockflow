<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE stock_movements
             ADD CONSTRAINT chk_stock_movements_quantity
             CHECK (quantity > 0)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE stock_movements
             DROP CHECK chk_stock_movements_quantity'
        );
    }
};