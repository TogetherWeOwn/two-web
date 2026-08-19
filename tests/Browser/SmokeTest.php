<?php

use Laravel\Dusk\Browser;

// The one journey the scaffold owns: a real browser can load the site. Every other
// journey belongs to the feature that ships it — see TWO-34 for the Dusk suite.
test('the site loads in a real browser', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/')
            ->assertSee('Together We Own');
    });
});
