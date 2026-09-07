<?php

namespace App\Support\Home;

final class HomePageContent
{
    /** @return array<string, mixed> */
    public static function lobbyLedger(): array
    {
        return [
            'masthead' => [
                'name' => 'Together We Own',
                'established' => 'Est. 1998',
                'strapline' => 'A close-knit gaming clan / mostly evenings / 18+',
            ],
            'hero' => [
                'title' => 'The lobby is open.',
                'lead' => 'We spent most of our life private. Now you can just turn up.',
                'promise' => 'Small enough that people notice when you come back.',
                'action' => 'Come say hello',
            ],
            'activity' => [
                'eyebrow' => '01 / Tonight in voice',
                'note' => 'The community is voice-first. The page never invents a busy room.',
                'quiet' => [
                    'label' => 'Quiet right now',
                    'description' => 'Tonight’s rooms appear here when the bot confirms them.',
                ],
                'honest' => [
                    'label' => 'No invented activity',
                    'description' => 'The invitation still works when nobody is online.',
                ],
            ],
            'ranks' => [
                'eyebrow' => '02 / You start as a Prospect',
                'title' => 'No application. No interview.',
                'body' => 'Show up a few times. Play. Become a Member. The ladder records trust and time, not grind.',
            ],
            'history' => [
                'eyebrow' => '03 / From forum threads to voice rooms',
                'title' => '1998 — now',
                'eras' => [
                    ['label' => '1998', 'description' => 'Founded'],
                    ['label' => 'Forum years', 'description' => 'Threads / signatures / rosters'],
                    ['label' => 'Voice years', 'description' => 'Duos / Trios / Quads / Squads'],
                    ['label' => 'Today', 'description' => 'Doors open'],
                ],
            ],
            'invitation' => [
                'eyebrow' => 'The honest invitation',
                'title' => 'Not a crowd. A place that knows your name.',
                'action' => 'Join the lobby',
            ],
        ];
    }
}
