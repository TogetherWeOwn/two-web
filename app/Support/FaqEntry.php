<?php

namespace App\Support;

/**
 * The FAQ entry registry (TOG-8863): the single source of truth for every
 * question on the static `/faq` page.
 *
 * Each case is keyed by a stable slug. Blade renders
 * `data-faq-entry="<slug>"` per entry from here, and votes persist against the
 * same slug — so rewording a question keeps its vote history instead of
 * orphaning it. Never change a slug's value; add a new case instead.
 *
 * Pure PHP: no database, no session, no cache. The `/faq` leaf renders from
 * this in routes/funnel.php with zero queries (pinned by FaqPageTest), so this
 * enum must never gain a query.
 */
enum FaqEntry: string
{
    case WhatIsTogetherWeOwn = 'what-is-together-we-own';
    case HowDoIJoin = 'how-do-i-join';
    case NoInviteNeeded = 'no-invite-needed';
    case NoApplicationInterview = 'no-application-interview';
    case CantPostWhatNow = 'cant-post-what-now';
    case WhatFirst = 'what-first';
    case RanksXpRoleRewards = 'ranks-xp-role-rewards';
    case RankRungs = 'rank-rungs';
    case LevelRoleRewards = 'level-role-rewards';
    case SundaySquadWhen = 'sunday-squad-when';
    case NeedGameMicExperience = 'need-game-mic-experience';
    case FindEventsGuestAccess = 'find-events-guest-access';
    case HowRsvpAnswersMean = 'how-rsvp-answers-mean';
    case GamePickerHow = 'game-picker-how';
    case PickerNothingHappened = 'picker-nothing-happened';
    case OpenSupportTicket = 'open-support-ticket';
    case TicketTranscript = 'ticket-transcript';
    case FillProfile = 'fill-profile';
    case PrivacyConductRules = 'privacy-conduct-rules';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Section title and anchor id, in page order. */
    public function section(): array
    {
        return match ($this) {
            self::WhatIsTogetherWeOwn,
            self::HowDoIJoin,
            self::NoInviteNeeded,
            self::NoApplicationInterview => ['title' => 'Getting in', 'anchor' => 'faq-getting-in'],

            self::CantPostWhatNow,
            self::WhatFirst => ['title' => 'Your first week', 'anchor' => 'faq-first-week'],

            self::RanksXpRoleRewards,
            self::RankRungs,
            self::LevelRoleRewards => ['title' => 'Ranks and rewards', 'anchor' => 'faq-ranks'],

            self::SundaySquadWhen,
            self::NeedGameMicExperience,
            self::FindEventsGuestAccess,
            self::HowRsvpAnswersMean => ['title' => 'Events', 'anchor' => 'faq-events'],

            self::GamePickerHow,
            self::PickerNothingHappened => ['title' => 'Game picker', 'anchor' => 'faq-onboarding'],

            self::OpenSupportTicket,
            self::TicketTranscript => ['title' => 'Support tickets', 'anchor' => 'faq-tickets'],

            self::FillProfile => ['title' => 'Your site profile', 'anchor' => 'faq-profile'],

            self::PrivacyConductRules => ['title' => 'Privacy and conduct', 'anchor' => 'faq-privacy'],
        };
    }

    public function question(): string
    {
        return match ($this) {
            self::WhatIsTogetherWeOwn => 'What is Together We Own?',
            self::HowDoIJoin => 'How do I join?',
            self::NoInviteNeeded => 'Do I need an invite, referral, or eligibility check?',
            self::NoApplicationInterview => 'Is there an application, interview, or skill requirement?',
            self::CantPostWhatNow => 'I joined but I can&rsquo;t post &mdash; what now?',
            self::WhatFirst => 'What should I do first?',
            self::RanksXpRoleRewards => 'How do ranks, XP, and role rewards work?',
            self::RankRungs => 'What are the rank rungs?',
            self::LevelRoleRewards => 'How do level role rewards work?',
            self::SundaySquadWhen => 'When do you actually play together?',
            self::NeedGameMicExperience => 'Do I need the game, a mic, or any experience?',
            self::FindEventsGuestAccess => 'Where do I find events, and do I need an account to look?',
            self::HowRsvpAnswersMean => 'How do I RSVP, and what do the answers mean?',
            self::GamePickerHow => 'How does the game picker work?',
            self::PickerNothingHappened => 'I tapped the picker and nothing happened. What now?',
            self::OpenSupportTicket => 'How do I open a private support ticket?',
            self::TicketTranscript => 'What happens to my ticket transcript?',
            self::FillProfile => 'How do I fill in my profile?',
            self::PrivacyConductRules => 'What do you store about me, and what are the rules?',
        };
    }

    /**
     * Answer paragraphs as trusted inner HTML (our own strings, rendered with
     * `{!! !!}`). Entities stay encoded in the source so the static output is
     * byte-identical to the hand-written page this replaces.
     *
     * @return list<string>
     */
    public function answerParagraphs(): array
    {
        return match ($this) {
            self::WhatIsTogetherWeOwn => [
                'A close-knit gaming clan, running since 1998, mostly evenings, 18+. We spent most of our life private; now the lobby is open and you can just turn up. Small enough that people notice when you come back.',
            ],
            self::HowDoIJoin => [
                'Approve once with Discord on the join page and we&rsquo;ll add you to the server &mdash; or use the Discord invite link instead. Then accept the rules on Discord&rsquo;s membership screen: that&rsquo;s the gate, and it&rsquo;s how we know you&rsquo;re really in.',
            ],
            self::NoInviteNeeded => [
                'No. The doors are open &mdash; no invite code, no referral, no waitlist. If you can open the join page, you&rsquo;re eligible.',
            ],
            self::NoApplicationInterview => [
                'No application, no interview, no tryout. Everyone starts as a Prospect: show up a few times, play, become a Member. The ladder records trust and time, not grind.',
            ],
            self::CantPostWhatNow => [
                'You&rsquo;re at a locked door: Discord holds new members as pending until they accept the rules on the membership screen. Accept them and you&rsquo;re in. If you joined a while ago and never accepted, that 30-second step is the whole fix.',
            ],
            self::WhatFirst => [
                'Three things: pick your games (that grants you each game&rsquo;s role and opens its channels), say hi in general, and come back once that week. Your first message starts a friendly clock &mdash; we measure how fast a newcomer gets their first human reply, so saying hi is genuinely contributing.',
            ],
            self::RanksXpRoleRewards => [
                'Hanging out earns XP: messages earn 15 XP (at most once a minute), voice time earns 5 XP per minute. Check yourself anytime with <code>/rank</code>, see the top ten with <code>/leaderboard</code>. At certain levels the bot grants you a role reward automatically &mdash; it never takes an earned reward away. Bots earn nothing, so the ladder is humans only.',
            ],
            self::RankRungs => [
                'Five, in order: <strong>Prospect &rarr; Member &rarr; Soldier &rarr; Veteran &rarr; Legend</strong>. Ranks stack &mdash; a Veteran still holds everything below. Legend is still unclaimed.',
            ],
            self::LevelRoleRewards => [
                'At certain levels the bot grants a role reward automatically &mdash; and it never takes an earned reward away. It only grants roles the staff configured, never invents new ones, and Discord&rsquo;s own permission and hierarchy rules still apply. If you passed a reward level and the role never arrived, tell a moderator.',
            ],
            self::SundaySquadWhen => [
                '<strong>Sunday Squad, every Sunday at 8pm Eastern</strong>, about an hour in the Lobby voice room. It runs whether there&rsquo;s two of us or eight. Coming back once that week &mdash; event or not &mdash; is what makes you a regular.',
            ],
            self::NeedGameMicExperience => [
                'No purchase, no skill, no sign-up, no need to say you&rsquo;re coming &mdash; just hop into the voice room. The current game is Fall Guys, which is free on PC, PlayStation, Xbox, Switch, and Android.',
            ],
            self::FindEventsGuestAccess => [
                'On the site&rsquo;s Events page: game nights, tournaments, whatever the community puts on. Anyone can read it &mdash; including signed-out visitors arriving from a Discord link. If there&rsquo;s nothing scheduled it says so plainly and names the last one that ran.',
            ],
            self::HowRsvpAnswersMean => [
                'Log in with Discord first &mdash; signed-out visitors get a log-in prompt instead of a button. Then it&rsquo;s one tap: <strong>I&rsquo;m in</strong>. One answer per member per event; changing your mind updates the same answer. Sunday Squad itself needs no RSVP: showing up in voice <em>is</em> the sign-up.',
            ],
            self::GamePickerHow => [
                'After you accept the rules, the welcome post in the landing channel mentions you with a game picker attached. Pick your games and the bot grants the matching roles &mdash; unticking removes them. It never DMs you. Changed your mind later? Pick again.',
            ],
            self::PickerNothingHappened => [
                'Rooms are checked against your own permissions at tap time: if a destination isn&rsquo;t open to you right now, you&rsquo;ll get a &ldquo;not open to you&rdquo; answer and nothing changes. Wait a moment and try again &mdash; or just say hello in the landing channel and a human will grab you.',
            ],
            self::OpenSupportTicket => [
                'Use the ticket or support button in the server: a private channel opens for you and staff, and a staff member claims it. One active ticket at a time &mdash; finish or close the open one before starting another.',
            ],
            self::TicketTranscript => [
                'Staff keep a text transcript for 90 days, staff-only, then it&rsquo;s deleted. Your message bodies live only in that transcript table &mdash; nowhere else in the database.',
            ],
            self::FillProfile => [
                'Sign in with Discord and open your profile. Three things are yours to write: a short bio, your games (one per line, up to 20), and your timezone. Everything else &mdash; name, avatar, join date, rank &mdash; comes from Discord and shows read-only. We never ask for or store your email.',
            ],
            self::PrivacyConductRules => [
                'We store Discord user IDs, timestamps, and channel IDs &mdash; enough to count joins and reporters honestly. We never store message content, email, location, or voice audio. Private support tickets keep a staff-only transcript for 90 days. Ask anytime to be removed and we delete your rows.',
                'The FAQ&rsquo;s optional Was this helpful? vote stores only a random voter ID in a first-party <code>faq_voter</code> cookie &mdash; no account, no email, no address &mdash; alongside your yes-or-no answer, so editors can see which answers need rewriting. Signed-in members are counted by account instead, with no cookie at all.',
                'The conduct version is one line: <strong>be someone a nervous newcomer is glad to meet.</strong> No harassment, no spam or sales DMs, no raid behavior. Staff calls are final in the moment; appeal afterwards by DMing a moderator, calmly, once.',
            ],
        };
    }

    /**
     * Entries grouped by section, in page order — what `faq.blade.php` loops.
     *
     * @return list<array{title: string, anchor: string, entries: list<self>}>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::cases() as $entry) {
            $section = $entry->section();
            $key = $section['anchor'];

            if (! isset($groups[$key])) {
                $groups[$key] = ['title' => $section['title'], 'anchor' => $section['anchor'], 'entries' => []];
            }

            $groups[$key]['entries'][] = $entry;
        }

        return array_values($groups);
    }
}
