<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicMarketingPagesTest extends TestCase
{
    public function test_public_socialscope_pages_render(): void
    {
        foreach (['/', '/pakete', '/login', '/register'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('SocialScope');
        }
    }
}
