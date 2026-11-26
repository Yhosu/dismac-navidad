<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branchs')) {
            return;
        }

        $branchIds = DB::table('branchs')
            ->where('name', 'CashBack Mayoristas')
            ->pluck('id');

        foreach ($branchIds as $branchId) {
            $hasForms = Schema::hasTable('forms')
                && DB::table('forms')->where('branch_id', $branchId)->exists();

            if (! $hasForms) {
                if (Schema::hasTable('prize_stock_history')) {
                    DB::table('prize_stock_history')->where('branch_id', $branchId)->delete();
                }
                if (Schema::hasTable('prize_branch')) {
                    DB::table('prize_branch')->where('branch_id', $branchId)->delete();
                }
                DB::table('branchs')->where('id', $branchId)->delete();
            }
        }
    }

    public function down(): void {}
};
