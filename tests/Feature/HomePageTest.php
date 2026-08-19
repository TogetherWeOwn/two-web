<?php

it('serves the home page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Together We Own');
});
