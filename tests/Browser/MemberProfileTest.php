<?php

use App\Models\Profile;
use App\Models\User;
use Laravel\Dusk\Browser;

it('lets a member edit their sparse profile with the keyboard-visible form', function () {
    $member = User::factory()->create([
        'display_name' => 'Wren',
        'avatar' => null,
    ]);

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit('/profile')
            ->assertVisible('[data-testid="profile-new-member"]')
            ->assertSee('Your profile has room to grow.')
            ->press('Add profile details')
            ->waitFor('[data-testid="profile-edit-form"]')
            ->type('bio', 'Usually on after work.')
            ->type('gamesText', "Minecraft\nHelldivers 2")
            ->type('timezone', 'Europe/London')
            ->press('Save')
            ->waitFor('[data-testid="profile-saved"]')
            ->assertSee('Usually on after work.')
            ->assertSee('Helldivers 2')
            ->assertSee('Europe/London')
            ->refresh()
            ->waitForText('Usually on after work.')
            ->assertSee('Usually on after work.')
            ->assertSee('Helldivers 2')
            ->assertSee('Europe/London');
    });

    expect(Profile::query()->whereBelongsTo($member)->sole())
        ->bio->toBe('Usually on after work.')
        ->games->toBe(['Minecraft', 'Helldivers 2'])
        ->timezone->toBe('Europe/London');
});

it('moves focus into the form on open and back to Edit profile on cancel (TOG-5634)', function () {
    $member = User::factory()->create();

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit('/profile')
            ->press('Add profile details')
            ->waitFor('[data-testid="profile-edit-form"]')
            // The trigger unmounts when the form renders: without the focus
            // contract the keyboard user is left on <body>.
            ->waitUntil('document.activeElement?.id === "edit-profile-heading"')
            ->assertSee('Edit your profile')
            ->press('Cancel')
            ->waitUntil('document.activeElement?.dataset?.testid === "profile-edit"')
            ->assertVisible('[data-testid="profile-edit"]');
    });
});

it('keeps an invalid edit open and gives the field an accessible error', function () {
    $member = User::factory()->create();

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit('/profile')
            ->press('Add profile details')
            ->waitFor('[data-testid="profile-edit-form"]')
            ->type('timezone', 'BST')
            ->press('Save')
            ->waitFor('[data-testid="profile-edit-failed"]')
            ->assertVisible('[data-testid="profile-edit-form"]')
            ->assertAttribute('#timezone', 'aria-invalid', 'true')
            ->assertSee('Check the highlighted fields and try again.');
    });
});
