<?php

namespace App\Filament\Resources\FeaturedContents\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Throwable;

class FeaturedContentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Content')
                    ->description('What the visitor sees in the home-page row.')
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->live()
                            ->placeholder('Community night on Friday')
                            ->helperText('The headline. Short enough to read at a glance.'),
                        Textarea::make('body')
                            ->rows(4)
                            ->columnSpanFull()
                            ->live()
                            ->helperText('One or two sentences under the headline. Leave empty for a headline-only row.'),
                        TextInput::make('url')
                            ->url()
                            ->live()
                            ->label('Link')
                            ->placeholder('https://example.org/charter')
                            ->helperText('Where the card sends a visitor who clicks the headline. Leave empty for no link.')
                            ->maxLength(255),
                        TextInput::make('image_url')
                            ->url()
                            ->live()
                            ->label('Image URL')
                            ->placeholder('https://example.org/photo.jpg')
                            ->helperText('Optional. A direct link to a real community photo — never stock or generated imagery. Shown full-width below the text.')
                            ->maxLength(255),
                        TextInput::make('image_alt')
                            ->live()
                            ->label('Image alt text')
                            ->placeholder('Members playing board games at the summer social')
                            ->helperText('Required when an image URL is set. One plain sentence describing the photo for screen-reader visitors.')
                            // An image with no description is silent for
                            // screen-reader visitors (TOG-8707): the URL and
                            // its description arrive together or not at all.
                            ->requiredWith('image_url')
                            ->maxLength(255),
                    ]),

                Section::make('Visibility')
                    ->description('Whether it shows, in what order, and for how long.')
                    ->schema([
                        Toggle::make('is_published')
                            ->label('Published')
                            ->live()
                            ->helperText('Off means staged: visible here, not on the landing page.'),
                        TextInput::make('position')
                            // The column is unsigned: a negative or fractional
                            // value passes `numeric` and then fails at the
                            // database. Reject it in the form instead.
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->helperText('Lower numbers appear first.'),

                        // An optional window, for content with a natural shelf life —
                        // an event announcement should not outlive the event.
                        DateTimePicker::make('starts_at')
                            ->label('Show from (UTC)')
                            ->live()
                            ->seconds(false),
                        DateTimePicker::make('ends_at')
                            ->label('Show until (UTC)')
                            ->live()
                            ->seconds(false)
                            ->after('starts_at'),
                    ]),

                Section::make('Preview')
                    ->description('How the row will look on the landing page, and whether visitors see it right now.')
                    ->schema([
                        Html::make(fn (Get $get): HtmlString => static::preview($get))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * A miniature of the home-page card, re-rendered as the moderator types
     * (every field above is `live()`). Status uses the same rules as
     * FeaturedContent::currentlyVisible so the preview never disagrees with
     * the site. Everything interpolated is escaped: this renders raw HTML.
     */
    protected static function preview(Get $get): HtmlString
    {
        $title = trim((string) ($get('title') ?? ''));
        $body = trim((string) ($get('body') ?? ''));
        $url = trim((string) ($get('url') ?? ''));
        $imageUrl = trim((string) ($get('image_url') ?? ''));
        $imageAlt = trim((string) ($get('image_alt') ?? ''));

        $html = '<div data-testid="featured-preview" style="border:1px solid #d6d3cb;border-radius:0.5rem;padding:1rem;max-width:42rem;">'
            .'<p style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.12em;opacity:0.65;margin:0;">From the community team</p>';

        if ($title === '' && $body === '' && $imageUrl === '') {
            $html .= '<p style="margin:0.75rem 0 0;opacity:0.65;">Nothing to preview yet — add a headline above.</p>';
        } else {
            if ($title !== '') {
                $headline = e($title);
                if ($url !== '') {
                    $headline = '<a href="'.e($url).'" style="text-decoration:underline;">'.$headline.'</a>';
                }
                $html .= '<p style="font-size:1.25rem;font-weight:700;margin:0.5rem 0 0;">'.$headline.'</p>';
            }
            if ($body !== '') {
                $html .= '<p style="margin:0.5rem 0 0;">'.nl2br(e($body)).'</p>';
            }
            if ($imageUrl !== '') {
                // Same contract as the public cards: written alt wins, the
                // headline stands in while the moderator is still typing.
                $previewAlt = $imageAlt !== '' ? $imageAlt : $title;
                $html .= '<img src="'.e($imageUrl).'" alt="'.e($previewAlt).'" loading="lazy" decoding="async" style="margin-top:0.75rem;max-width:100%;">';
            }
        }

        $html .= '<p style="font-size:0.8rem;margin:0.75rem 0 0;opacity:0.75;">'.e(static::visibilityStatus($get)).'</p>'
            .'</div>';

        return new HtmlString($html);
    }

    /** The status line under the preview, in the scope's own terms. */
    protected static function visibilityStatus(Get $get): string
    {
        if (! $get('is_published')) {
            return 'Staged — turn Published on to show it on the landing page.';
        }

        $now = now();
        $startsAt = static::parseDateTime($get('starts_at'));
        $endsAt = static::parseDateTime($get('ends_at'));

        if ($startsAt && $startsAt->greaterThan($now)) {
            return 'Scheduled — appears '.$startsAt->format('j M Y, H:i').' UTC.';
        }

        if ($endsAt && ! $endsAt->greaterThan($now)) {
            return 'Expired — the window closed '.$endsAt->format('j M Y, H:i').' UTC, so visitors do not see it.';
        }

        return 'Live — visitors see this on the landing page right now.';
    }

    protected static function parseDateTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'UTC');
        } catch (Throwable) {
            return null;
        }
    }
}
