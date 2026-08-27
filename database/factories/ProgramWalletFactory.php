<?php

namespace Database\Factories;

use App\Models\Programs\Program;
use App\Models\Programs\ProgramWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProgramWalletFactory extends Factory
{
    protected $model = ProgramWallet::class;
    public function definition(): array
    {
        return ['program_id' => Program::factory(), 'total_deposited' => 100000, 'total_disbursed' => 0, 'total_reserved' => 0, 'balance' => 100000, 'currency' => 'KES', 'status' => 'active'];
    }
}
