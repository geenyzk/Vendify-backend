<?php

namespace Tests\Unit;

use App\Services\AiManager\Tools\CreatePromotionTool;
use Tests\TestCase;

class CreatePromotionToolTest extends TestCase
{
    public function test_tool_accepts_multiple_targets_without_required_single_target(): void
    {
        $tool = new CreatePromotionTool();
        $parameters = $tool->parameters();

        $this->assertArrayHasKey('required', $parameters);
        $this->assertNotContains('target', $parameters['required']);
        $this->assertArrayHasKey('targets', $parameters['properties']);
    }
}
