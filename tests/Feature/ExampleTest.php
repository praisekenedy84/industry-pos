<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_marketing_page_loads(): void
    {
        $this->get('http://localhost/')->assertOk();
    }
}
