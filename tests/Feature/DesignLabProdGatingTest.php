<?php

// TOG-5632: /design-lab/* must never serve production traffic. The routes are
// not registered in production; these tests pin the controller-level check so
// a cached or manually registered route still fails closed — the same pattern
// as the staging QA login.

afterEach(function () {
    app()->instance('env', 'testing');
});

it('returns 404 for design-lab routes in production even if the route is registered', function (string $path) {
    app()->instance('env', 'production');

    $this->get($path)->assertNotFound();
})->with(['/design-lab/hallmark', '/design-lab/taste']);
