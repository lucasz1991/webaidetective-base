<?php

namespace Tests\Feature;

use Tests\TestCase;

class UserNavigationMenuViewTest extends TestCase
{
    public function test_navigation_height_measurement_is_null_safe_and_initialized_once(): void
    {
        $view = file_get_contents(resource_path('views/livewire/user-navigation-menu.blade.php'));

        $this->assertStringContainsString('measureNavHeight()', $view);
        $this->assertStringContainsString("this.\$refs.nav || this.\$el.querySelector('[data-user-navigation]')", $view);
        $this->assertStringContainsString('if (!nav) return;', $view);
        $this->assertStringContainsString('data-user-navigation', $view);
        $this->assertStringNotContainsString('this.$refs.nav.offsetHeight', $view);
        $this->assertStringNotContainsString('x-init="init()"', $view);
    }
}
