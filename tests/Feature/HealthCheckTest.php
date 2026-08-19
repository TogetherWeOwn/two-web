<?php

// The deploy and the CI pipeline both poll /up. If the app cannot boot far enough
// to answer it, nothing else in this suite is meaningful.
it('answers the health check', function () {
    $this->get('/up')->assertOk();
});
