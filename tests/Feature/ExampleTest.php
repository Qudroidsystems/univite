<?php

test('the home page sends guests to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});
