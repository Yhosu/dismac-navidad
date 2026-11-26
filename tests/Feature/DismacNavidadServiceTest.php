<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Form;
use App\Models\Prize;
use App\Models\PrizeBranch;
use App\Models\PrizeStockHistory;
use App\Models\User;
use App\Repositories\ParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DismacNavidadServiceTest extends TestCase
{
    use RefreshDatabase;

    private ParticipantService $service;

    private Branch $ecommerceBranch;

    private Branch $retailBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(ParticipantService::class);

        $this->ecommerceBranch = Branch::create([
            'id' => (string) Str::uuid(),
            'name' => 'Central Ecommerce',
            'city' => 'Nacional',
            'channel' => 'ECOMMERCE',
        ]);

        $this->retailBranch = Branch::create([
            'id' => (string) Str::uuid(),
            'name' => 'Sucursal Equipetrol',
            'city' => 'Santa Cruz',
            'channel' => 'RETAIL',
        ]);
    }

    private function createPrizeWithStock(Branch $branch, string $name, float $amount, int $stock): Prize
    {
        $prize = Prize::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'amount' => $amount,
            'active' => true,
        ]);

        PrizeStockHistory::create([
            'prize_id' => $prize->id,
            'branch_id' => $branch->id,
            'quantity' => $stock,
            'type' => 'addition',
            'description' => 'Stock inicial test',
        ]);

        return $prize;
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Juan Pérez',
            'cellphone' => '70012345',
            'ci_number' => '12345678',
            'document_type' => 'CI',
            'business_unit' => 'RET',
            'control_code' => 'CTRL-'.Str::random(8),
            'sale_type' => 'CONTADO',
            'city' => 'Santa Cruz',
            'city_agency' => 'Equipetrol',
            'email' => 'juan@example.com',
            'total_amount' => 2000,
            'sale_date' => now()->toDateString(),
            'sale_items' => [
                [
                    'name' => 'Producto Test',
                    'code_brand' => 10,
                    'amount' => 2000,
                    'quantity' => 1,
                ],
            ],
        ], $overrides);
    }

    public function test_purchase_below_1500_generates_zero_attempts(): void
    {
        $result = $this->service->registerParticipant($this->basePayload([
            'total_amount' => 1499,
            'sale_items' => [['name' => 'Item', 'code_brand' => 10, 'amount' => 1499, 'quantity' => 1]],
        ]));

        $this->assertEquals(0, $result['total_attempts']);
        $this->assertNull($result['sphere_color']);
        $this->assertNull($result['sphere_tier']);
        $this->assertDatabaseCount('forms', 0);
    }

    public function test_retail_purchase_assigns_sphere_color_without_auto_prize(): void
    {
        $result = $this->service->registerParticipant($this->basePayload([
            'business_unit' => 'RET',
            'total_amount' => 2500,
        ]));

        $this->assertEquals(1, $result['total_attempts']);
        $this->assertEquals('Roja', $result['sphere_color']);
        $this->assertEquals('T1', $result['sphere_tier']);
        $this->assertFalse($result['is_ecommerce']);
        $this->assertStringContainsString('Roja', $result['message_promotion']);
        $this->assertStringContainsString('POS', $result['message_promotion']);

        $form = Form::first();
        $this->assertNotNull($form);
        $this->assertEquals('Roja', $form->sphere_color);
        $this->assertEquals('T1', $form->sphere_tier);
        $this->assertNull($form->prize_id);
        $this->assertNull($form->branch_id);
        $this->assertEquals('registered', $form->status);
    }

    public function test_ecommerce_purchase_auto_assigns_prize_within_sphere_range_and_prompts_virtual_balloon(): void
    {
        $prize50 = $this->createPrizeWithStock($this->ecommerceBranch, 'Vale Bs 50', 50, 5);
        $prize200 = $this->createPrizeWithStock($this->ecommerceBranch, 'Vale Bs 200', 200, 5);
        $prize5000 = $this->createPrizeWithStock($this->ecommerceBranch, 'Vale Bs 5000', 5000, 5);

        // Plateada (T2): 3000 - 5499 -> allowed [100, 200, 500], so only $prize200 qualifies
        $result = $this->service->registerParticipant($this->basePayload([
            'business_unit' => 'ECO',
            'total_amount' => 3500,
        ]));

        $this->assertEquals(1, $result['total_attempts']);
        $this->assertEquals('Plateada', $result['sphere_color']);
        $this->assertEquals('T2', $result['sphere_tier']);
        $this->assertTrue($result['is_ecommerce']);
        $this->assertStringContainsString('Plateada', $result['message_promotion']);
        $this->assertStringContainsString('globo virtual', $result['message_promotion']);

        $form = Form::first();
        $this->assertEquals($prize200->id, $form->prize_id);
        $this->assertEquals($this->ecommerceBranch->id, $form->branch_id);
        $this->assertEquals('registered', $form->status);

        // Stock decremented from 5 to 4
        $stock200 = PrizeBranch::where('prize_id', $prize200->id)
            ->where('branch_id', $this->ecommerceBranch->id)
            ->value('stock');
        $this->assertEquals(4, $stock200);

        // Calling markFormAsUsed (when popping virtual balloon) should NOT double-decrement stock
        $usedResult = $this->service->markFormAsUsed($form->id);
        $this->assertTrue($usedResult['success']);
        $this->assertEquals('used', $form->fresh()->status);

        $stockAfterUsed = PrizeBranch::where('prize_id', $prize200->id)
            ->where('branch_id', $this->ecommerceBranch->id)
            ->value('stock');
        $this->assertEquals(4, $stockAfterUsed);
    }

    public function test_dorada_sphere_allows_high_prizes(): void
    {
        $prize50 = $this->createPrizeWithStock($this->ecommerceBranch, 'Vale Bs 50', 50, 5);
        $prize10000 = $this->createPrizeWithStock($this->ecommerceBranch, 'Vale Bs 10000', 10000, 2);

        $result = $this->service->registerParticipant($this->basePayload([
            'business_unit' => 'ECO',
            'total_amount' => 6000,
        ]));

        $this->assertEquals('Dorada', $result['sphere_color']);
        $this->assertEquals('T3', $result['sphere_tier']);

        $form = Form::first();
        $this->assertEquals($prize10000->id, $form->prize_id);
    }

    public function test_daily_limit_one_participation_per_ci_per_day(): void
    {
        $first = $this->service->registerParticipant($this->basePayload([
            'ci_number' => '99887766',
            'control_code' => 'CTRL-1',
            'total_amount' => 2000,
        ]));

        $this->assertEquals(1, $first['total_attempts']);

        $second = $this->service->registerParticipant($this->basePayload([
            'ci_number' => '99887766',
            'control_code' => 'CTRL-2',
            'total_amount' => 5500,
        ]));

        $this->assertEquals(0, $second['total_attempts']);
        $this->assertStringContainsString('una participación por día', $second['message_promotion']);
        $this->assertDatabaseCount('forms', 1);
    }

    public function test_find_by_ci_number_returns_sphere_info(): void
    {
        $this->service->registerParticipant($this->basePayload([
            'ci_number' => '55443322',
            'business_unit' => 'ECO',
            'total_amount' => 6000,
        ]));

        $found = $this->service->findByCiNumber('55443322');

        $this->assertEquals(1, $found['total_attempts']);
        $this->assertEquals('Dorada', $found['sphere_color']);
        $this->assertEquals('T3', $found['sphere_tier']);
        $this->assertTrue($found['is_ecommerce']);
        $this->assertStringContainsString('globo virtual', $found['message_promotion']);
    }

    public function test_user_branch_account_helper(): void
    {
        $branchUser = User::create([
            'name' => 'POS Equipetrol',
            'email' => 'pos@dismac.com.bo',
            'password' => bcrypt('secret123'),
            'role' => 'subadmin',
            'branch_id' => $this->retailBranch->id,
        ]);

        $adminUser = User::create([
            'name' => 'Admin',
            'email' => 'admin@dismac.com.bo',
            'password' => bcrypt('secret123'),
            'role' => 'admin',
            'branch_id' => null,
        ]);

        $this->assertTrue($branchUser->isBranchAccount());
        $this->assertEquals($this->retailBranch->id, $branchUser->branch->id);
        $this->assertFalse($adminUser->isBranchAccount());
    }
}
