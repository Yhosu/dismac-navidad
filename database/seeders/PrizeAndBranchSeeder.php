<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Prize;
use App\Models\PrizeBranch;
use App\Models\PrizeStockHistory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PrizeAndBranchSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Crear Sucursal Ecommerce Central
        $centralDismac = Branch::where('channel', 'ECOMMERCE')->first();
        if (! $centralDismac) {
            $centralDismac = Branch::create([
                'id' => (string) Str::uuid(),
                'name' => 'Central Dismac Ecommerce',
                'channel' => 'ECOMMERCE',
                'city' => 'Nacional',
                'address' => 'Central Dismac',
            ]);
        } else {
            $centralDismac->update(['city' => 'Nacional']);
        }

        // Eliminar sucursal legado CashBack Mayoristas si no tiene formularios asociados
        $legacyMayorista = Branch::where('name', 'CashBack Mayoristas')->first();
        if ($legacyMayorista && \App\Models\Form::where('branch_id', $legacyMayorista->id)->doesntExist()) {
            PrizeStockHistory::where('branch_id', $legacyMayorista->id)->delete();
            PrizeBranch::where('branch_id', $legacyMayorista->id)->delete();
            $legacyMayorista->delete();
        }

        // 2. Definición de Premios y Stock por defecto (100 unidades por premio)
        $prizesData = [
            [
                'name' => 'Premio 10000 Bs',
                'amount' => 10000.00,
                'description' => 'Vale / Premio de 10000 Bs',
                'stock_ecommerce' => 100,
            ],
            [
                'name' => 'Premio 5000 Bs',
                'amount' => 5000.00,
                'description' => 'Vale / Premio de 5000 Bs',
                'stock_ecommerce' => 100,
            ],
            [
                'name' => 'Premio 3000 Bs',
                'amount' => 3000.00,
                'description' => 'Vale / Premio de 3000 Bs',
                'stock_ecommerce' => 100,
            ],
            [
                'name' => 'Premio 2000 Bs',
                'amount' => 2000.00,
                'description' => 'Vale / Premio de 2000 Bs',
                'stock_ecommerce' => 100,
            ],
            [
                'name' => 'Premio 1000 Bs',
                'amount' => 1000.00,
                'description' => 'Vale / Premio de 1000 Bs',
                'stock_ecommerce' => 100,
            ],
            [
                'name' => 'Premio 500 Bs',
                'amount' => 500.00,
                'description' => 'Vale / Premio de 500 Bs',
                'stock_ecommerce' => 100,
            ],
            [
                'name' => 'Premio 250 Bs',
                'amount' => 250.00,
                'description' => 'Vale / Premio de 250 Bs',
                'stock_ecommerce' => 100,
            ],
            [
                'name' => 'Premio 200 Bs',
                'amount' => 200.00,
                'description' => 'Vale / Premio de 200 Bs',
                'stock_ecommerce' => 100,
            ],
            [
                'name' => 'Premio 100 Bs',
                'amount' => 100.00,
                'description' => 'Vale / Premio de 100 Bs',
                'stock_ecommerce' => 100,
            ],
            [
                'name' => 'Premio 50 Bs',
                'amount' => 50.00,
                'description' => 'Vale / Premio de 50 Bs',
                'stock_ecommerce' => 100,
            ],
        ];

        foreach ($prizesData as $data) {
            $prize = Prize::where('name', $data['name'])->first();
            if (! $prize) {
                $prize = Prize::create([
                    'id' => (string) Str::uuid(),
                    'name' => $data['name'],
                    'amount' => $data['amount'],
                    'description' => $data['description'],
                    'active' => true,
                ]);
            }

            // Stock Central Dismac (ECOMMERCE)
            $historyEco = PrizeStockHistory::where('prize_id', $prize->id)
                ->where('branch_id', $centralDismac->id)
                ->where('description', 'Carga inicial de stock - Central Dismac (ECOMMERCE)')
                ->first();

            if (! $historyEco) {
                PrizeStockHistory::create([
                    'id' => (string) Str::uuid(),
                    'prize_id' => $prize->id,
                    'branch_id' => $centralDismac->id,
                    'quantity' => $data['stock_ecommerce'],
                    'type' => 'addition',
                    'description' => 'Carga inicial de stock - Central Dismac (ECOMMERCE)',
                ]);

                $ecoStock = PrizeBranch::firstOrCreate([
                    'prize_id' => $prize->id,
                    'branch_id' => $centralDismac->id,
                ]);
                $ecoStock->stock = $data['stock_ecommerce'];
                $ecoStock->save();
            }
        }
    }
}
