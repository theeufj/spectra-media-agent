<?php

namespace Database\Factories;

use App\Models\AiCost;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiCostFactory extends Factory
{
    protected $model = AiCost::class;

    public function definition(): array
    {
        $models = [
            'gemini-3.5-flash', 'gemini-2.5-flash', 'gemini-2.5-flash-lite',
            'gemini-3.1-pro-preview', 'gemini-3.1-flash-image-preview',
        ];
        $operations = ['generateContent', 'generateImage', 'embedContent', 'startVideoGeneration'];
        $taskTypes  = ['creative', 'analytical', 'extraction', 'classification', 'strategy', null];

        $inputTokens  = $this->faker->numberBetween(100, 50000);
        $outputTokens = $this->faker->numberBetween(50, 8192);

        return [
            'campaign_id'   => null,
            'customer_id'   => null,
            'service'       => 'Gemini',
            'operation'     => $this->faker->randomElement($operations),
            'model'         => $this->faker->randomElement($models),
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
            'cached_tokens' => 0,
            'cost'          => round(($inputTokens * 0.075 + $outputTokens * 0.30) / 1_000_000, 6),
            'duration_ms'   => $this->faker->numberBetween(200, 15000),
            'task_type'     => $this->faker->randomElement($taskTypes),
            'metadata'      => null,
        ];
    }
}
