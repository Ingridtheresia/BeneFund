<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AboutPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_about_page_is_public_and_explains_the_application_and_developer(): void
    {
        $this->get(route('about'))->assertOk()->assertViewIs('about')
            ->assertSee('TUJUAN KAMI')
            ->assertSee('FITUR UTAMA')
            ->assertSee('CARA KERJA')
            ->assertSee('Tim Pengembang BeneFund')
            ->assertSee('https://github.com/Ingridtheresia/BeneFund', false)
            ->assertSee(route('campaigns'), false)
            ->assertSee(route('transparency'), false)
            ->assertSee('Midtrans Sandbox')
            ->assertSee(asset('css/about.css'), false);
    }
}
