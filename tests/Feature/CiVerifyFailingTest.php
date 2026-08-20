<?php

it('is deliberately broken to prove CI catches a failing test', function () {
    expect(1)->toBe(2);
});
