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

    public function test_all_navigation_dropdowns_use_the_anchor_dropdown_component(): void
    {
        $view = file_get_contents(resource_path('views/livewire/user-navigation-menu.blade.php'));
        $component = file_get_contents(resource_path('views/components/ui/dropdown/anchor-dropdown.blade.php'));

        $this->assertSame(4, substr_count($view, '<x-ui.dropdown.anchor-dropdown'));
        $this->assertStringNotContainsString('<x-dropdown ', $view);
        $this->assertStringNotContainsString('x-data="{ open: false, closeTimer:', $view);
        $this->assertStringNotContainsString('class="absolute right-0 mt-2 w-80', $view);
        $this->assertStringContainsString('<x-modal wire:model="showMessagePreviewModal"', $view);
        $this->assertStringContainsString('wire:key="user-nav-message-preview-modal-', $view);
        $this->assertStringContainsString('x-show="isMobileMenuOpen || !isMobile"', $view);
        $this->assertStringContainsString('overlay-classes="fixed inset-0 z-40 bg-black/40 md:hidden"', $view);
        $this->assertStringContainsString('@dropdown-open.window=', $component);
        $this->assertStringContainsString("\$dispatch('dropdown-open', { id: dropdownId })", $component);
    }
}
