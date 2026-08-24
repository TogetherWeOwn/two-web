<?php

// Placeholder copy. The Designer owns the wording (TWO-26); this exists so every
// outcome of a join attempt is a sentence a member can act on rather than a blank
// page. Replace the strings, not the keys — `join.result.*` is keyed by the bot's
// wire outcomes and by App\Http\Controllers\JoinController.

return [

    'heading' => 'Join Together We Own',

    'one_click' => 'Join with Discord',

    'invite' => 'Open the Discord invite',

    'open_server' => 'Open Together We Own in Discord',

    'unconfigured' => 'The join link is not set up on this site yet. Please try again shortly.',

    'result' => [

        // The one that matters. It also tells them about rules screening, because
        // with screening on, Discord adds people as `pending`: they are in the
        // server and cannot say anything yet. A member who thinks they have
        // joined and then cannot talk assumes we are broken. Joined and active
        // are two different things and this is where a person first meets that.
        'added' => "You're in. One more step: open Discord and accept the server rules — until you do, you can read but not post.",

        // Not an error. Discord answers this when they were already a member, and
        // the useful thing to give them is the way in, not an apology.
        'already_member' => "You're already a member of Together We Own — here's the way back in.",

        'denied' => 'You cancelled the Discord approval, so we did not add you. You can try again whenever you like.',

        'expired' => 'That attempt took too long and expired. Please try again.',

        // Covers every failure that is ours or Discord's rather than theirs: the
        // bot being down, the action not being switched on, Discord refusing. The
        // member does not need to know which, only that there is another way in.
        'unavailable' => 'We could not add you automatically just now. Use the invite below — it gets you to exactly the same place.',

    ],

];
