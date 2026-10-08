<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests see the public landing page with the apply and sign-in doors', function () {
    $this->get('/')->assertOk()->assertSee('Apply for admission')->assertSee('Continue my application')->assertSee(route('login'));
});
