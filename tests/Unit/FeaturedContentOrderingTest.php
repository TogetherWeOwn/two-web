<?php

use App\Filament\Resources\FeaturedContents\FeaturedContentResource;
use App\Filament\Resources\FeaturedContents\Pages\ListFeaturedContents;
use App\Models\FeaturedContent;
use Filament\Tables\Table;
use Illuminate\Database\Connection;

function disconnectedFeaturedContentForOrdering(): FeaturedContent
{
    return (new class extends FeaturedContent
    {
        public function getConnection(): Connection
        {
            return new Connection(static fn (): PDO => throw new LogicException('Ordering tests must not connect to a database.'));
        }
    })->setTable('featured_contents');
}

it('orders visible featured content by position then ascending id', function () {
    $query = disconnectedFeaturedContentForOrdering()->newQuery()->currentlyVisible()->toBase();

    expect($query->orders)->toBe([
        ['column' => 'position', 'direction' => 'asc'],
        ['column' => 'id', 'direction' => 'asc'],
    ]);
});

it('explicitly orders the featured admin table by position then ascending id', function () {
    $table = FeaturedContentResource::table(Table::make(new ListFeaturedContents));
    $query = disconnectedFeaturedContentForOrdering()->newQuery();

    // Evaluate the configured default before Filament's implicit key fallback.
    expect($table->getDefaultSort($query, 'asc'))->toBe($query)
        ->and($query->getQuery()->orders)->toBe([
            ['column' => 'position', 'direction' => 'asc'],
            ['column' => 'id', 'direction' => 'asc'],
        ]);
});
