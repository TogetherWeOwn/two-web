<?php

it('serves the Lobby Ledger homepage', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Together We Own')
        ->assertSee('The lobby is open.')
        ->assertSee('Tonight in voice')
        ->assertSee('No application. No interview.')
        ->assertSee('From forum threads to voice rooms')
        ->assertSee('Not a crowd. A place that knows your name.')
        ->assertSee('data-testid="discord-join"', escape: false);
});
