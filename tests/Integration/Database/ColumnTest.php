<?php

declare(strict_types=1);

namespace Tests\Tempest\Integration\Database;

use PHPUnit\Framework\Attributes\Test;
use Tempest\Database\Column;
use Tempest\Database\HasMany;
use Tempest\Database\HasOne;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\MigratesUp;
use Tempest\Database\Migrations\CreateMigrationsTable;
use Tempest\Database\PrimaryKey;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\Table;
use Tempest\Database\Uuid;
use Tempest\Database\Virtual;
use Tests\Tempest\Integration\FrameworkIntegrationTestCase;

use function Tempest\Database\query;

final class ColumnTest extends FrameworkIntegrationTestCase
{
    #[Test]
    public function renamed_properties_are_read_written_and_queried(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class);

        ColumnAuthor::create(displayName: 'Zoe');
        ColumnAuthor::create(displayName: 'Ada');

        $this->assertSame('Ada', ColumnAuthor::select()->orderBy('displayName')->first()->displayName);

        $select = ColumnAuthor::select();
        $select->whereField('displayName', 'Zoe');

        $author = $select->first();
        $this->assertInstanceOf(ColumnAuthor::class, $author);

        $author->displayName = 'Bea';
        $author->save();

        $this->assertSame('Bea', ColumnAuthor::select()->get($author->authorId)->displayName);

        $author->displayName = 'Unsaved';
        $this->assertSame('Bea', $author->refresh()->displayName);

        $author->delete();
        $this->assertNull(ColumnAuthor::select()->get($author->authorId));
    }

    #[Test]
    public function eager_and_scoped_relations_use_physical_columns_even_with_keywords_and_name_collisions(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class, ColumnBook::class);

        $author = ColumnAuthor::create(displayName: 'Ada');
        ColumnBook::create(bookTitle: 'First', author: $author);
        ColumnBook::create(bookTitle: 'Second', author: $author);

        $loaded = ColumnAuthor::select()->with('books.author')->get($author->authorId);
        $this->assertCount(2, $loaded->books);
        $this->assertSame('Ada', $loaded->books[0]->author->displayName);
        $this->assertSame('First', $loaded->query('books')->select()->orderBy('bookTitle')->first()->bookTitle);
        $this->assertSame('Ada', $loaded->books[0]->query('author')->select()->first()->displayName);

        $replacement = ColumnAuthor::create(displayName: 'Bea');
        $book = $loaded->books[0];
        $book->update(author: $replacement);

        $this->assertSame('Bea', ColumnBook::select()->with('author')->get($book->bookId)->author->displayName);
    }

    #[Test]
    public function renamed_uuid_and_explicit_query_fields_work_with_iterable_inserts(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnTag::class);

        $id = query(ColumnTag::class)->insert(tagName: 'news')->execute();

        $tag = query(ColumnTag::class)->select('tagId', 'tagName')->get($id);
        $this->assertInstanceOf(ColumnTag::class, $tag);
        $this->assertSame($id->value, $tag->tagId->value);
        $this->assertSame('news', $tag->tagName);
        $this->assertSame(1, query(ColumnTag::class)->count('tagName')->execute());
        $this->assertSame('news', query(ColumnTag::class)->max('tagName'));
    }

    #[Test]
    public function renamed_properties_can_be_selected_as_a_comma_separated_list(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class);

        $author = ColumnAuthor::create(displayName: 'Ada');
        $loaded = query(ColumnAuthor::class)->select('authorId, displayName')->get($author->authorId);

        $this->assertSame($author->authorId->value, $loaded->authorId->value);
        $this->assertSame('Ada', $loaded->displayName);

        $single = query(ColumnAuthor::class)->select(' displayName ')->get($author->authorId);

        $this->assertSame('Ada', $single->displayName);

        $raw = query(ColumnAuthor::class)->select("'Ada, displayName, Lovelace' AS label")->build()->fetchFirst();

        $this->assertSame('Ada, displayName, Lovelace', $raw['label']);
    }

    #[Test]
    public function replacing_a_relation_preserves_other_owners_rows_when_names_overlap(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class, ColumnBook::class);

        $author = ColumnAuthor::create(displayName: 'Ada');
        $other = ColumnAuthor::create(displayName: 'Bea');
        $oldBook = ColumnBook::create(bookTitle: 'Old', author: $author);
        $otherBook = ColumnBook::create(bookTitle: 'Keep', author: $other, writer_ref: $author->authorId->value);

        $author->update(books: [['bookTitle' => 'New', 'writer_ref' => 42]]);

        $this->assertSame('Keep', ColumnBook::select()->get($otherBook->bookId)?->bookTitle);
        $this->assertNull(ColumnBook::select()->get($oldBook->bookId));

        $replacement = ColumnAuthor::select()->with('books')->get($author->authorId)->books[0];
        $this->assertSame('New', $replacement->bookTitle);
        $this->assertSame(42, $replacement->writer_ref);
    }

    #[Test]
    public function eagerly_loads_keyword_foreign_keys_in_both_directions_and_nested_relations(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class, ColumnReview::class);

        $author = ColumnAuthor::create(displayName: 'Ada');
        $review = ColumnReview::create(author: $author);

        $this->assertSame('Ada', ColumnReview::select()->with('author')->get($review->id)->author->displayName);

        $loaded = ColumnAuthor::select()->with('reviews.author')->get($author->authorId);
        $this->assertSame($review->id->value, $loaded->reviews[0]->id->value);
        $this->assertSame('Ada', $loaded->reviews[0]->author->displayName);

        $unassigned = ColumnReview::create(author: null);
        $this->assertNull(ColumnReview::select()->with('author')->get($unassigned->id)->author);
    }

    #[Test]
    public function scopes_and_updates_relations_with_keyword_foreign_keys(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class, ColumnReview::class);

        $author = ColumnAuthor::create(displayName: 'Ada');
        $other = ColumnAuthor::create(displayName: 'Bea');
        $review = ColumnReview::create(author: $author);

        $this->assertSame($review->id->value, $author->query('reviews')->select()->first()->id->value);
        $this->assertSame('Ada', $review->query('author')->select()->first()->displayName);
        $this->assertNull($other->query('reviews')->select()->first());

        $review->update(author: $other);

        $this->assertNull($author->query('reviews')->select()->first());
        $this->assertSame('Bea', $review->query('author')->select()->first()->displayName);
    }

    #[Test]
    public function filters_relation_existence_using_keyword_foreign_keys(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class, ColumnReview::class);

        $author = ColumnAuthor::create(displayName: 'Ada');
        $other = ColumnAuthor::create(displayName: 'Bea');
        $review = ColumnReview::create(author: $author);
        $unassigned = ColumnReview::create(author: null);

        $this->assertSame($author->authorId->value, ColumnAuthor::select()->whereHas('reviews')->first()->authorId->value);
        $this->assertSame($other->authorId->value, ColumnAuthor::select()->whereDoesntHave('reviews')->first()->authorId->value);
        $this->assertSame($review->id->value, ColumnReview::select()->whereHas('author')->first()->id->value);
        $this->assertSame($unassigned->id->value, ColumnReview::select()->whereDoesntHave('author')->first()->id->value);
        $this->assertNull(ColumnAuthor::select()->whereHas('reviews', count: 2)->first());
    }

    #[Test]
    public function nested_inserts_preserve_properties_that_overlap_physical_foreign_keys_and_their_prefixes(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class, ColumnBook::class);

        $child = ColumnBook::new(bookTitle: 'First', writer_ref: 42, writer: 'Keep this value');
        $author = ColumnAuthor::create(displayName: 'Ada', books: [$child]);
        $book = $author->query('books')->select()->with('author')->first();

        $this->assertSame(42, $book->writer_ref);
        $this->assertSame('Keep this value', $book->writer);
        $this->assertSame($author->authorId->value, $book->author->authorId->value);
        $this->assertSame(1, query(ColumnAuthor::class)->count()->execute());
    }

    #[Test]
    public function replacing_keyword_relations_preserves_other_owners_and_accepts_an_empty_list(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class, ColumnReview::class);

        $author = ColumnAuthor::create(displayName: 'Ada', reviews: [[]]);
        $other = ColumnAuthor::create(displayName: 'Bea', reviews: [[]]);
        $oldReview = $author->query('reviews')->select()->first();
        $otherReview = $other->query('reviews')->select()->first();

        $author->update(reviews: [[]]);

        $this->assertNull(ColumnReview::select()->get($oldReview->id));
        $this->assertCount(1, $author->query('reviews')->select()->all());

        $author->update(reviews: []);

        $this->assertNull($author->query('reviews')->select()->first());
        $this->assertInstanceOf(ColumnReview::class, ColumnReview::select()->get($otherReview->id));
    }

    #[Test]
    public function inserting_and_replacing_a_custom_has_one_uses_physical_foreign_and_keyword_primary_keys(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class, ColumnAccount::class);

        $account = ColumnAccount::create(author: ['displayName' => 'Ada']);
        $author = ColumnAccount::select()->with('author')->get($account->accountId)->author;

        $this->assertSame('Ada', $author->displayName);

        query(ColumnAccount::class)
            ->update(author: ['displayName' => 'Bea'])
            ->whereField('accountId', $account->accountId)
            ->execute();

        $loaded = ColumnAccount::select()->with('author')->get($account->accountId);

        $this->assertSame('Bea', $loaded->author->displayName);
        $this->assertSame($loaded->author->authorId->value, $loaded->authorId);
        $this->assertNull(ColumnAuthor::get($author->authorId));
    }
}

#[Table('column_authors')]
final class ColumnAuthor implements MigratesUp
{
    use IsDatabaseModel;

    #[Virtual]
    public string $name = 'column_authors';

    #[Column('author_id')]
    public PrimaryKey $authorId;

    #[Column('display_name')]
    public string $displayName;

    /** @var \Tests\Tempest\Integration\Database\ColumnBook[] */
    #[HasMany(ownerJoin: 'writer_ref')]
    public array $books = [];

    /** @var \Tests\Tempest\Integration\Database\ColumnReview[] */
    #[HasMany(ownerJoin: 'order')]
    public array $reviews = [];

    public function up(): QueryStatement
    {
        return new CreateTableStatement('column_authors')
            ->primary('author_id')
            ->string('display_name');
    }
}

#[Table('column_books')]
final class ColumnBook implements MigratesUp
{
    use IsDatabaseModel;

    #[Virtual]
    public string $name = 'column_books';

    #[Column('book_id')]
    public PrimaryKey $bookId;

    #[Column('order')]
    public string $bookTitle;

    #[Column('writer_ref')]
    public ColumnAuthor $author;

    #[Column('legacy_code')]
    public int $writer_ref = 0;

    public string $writer = '';

    public function up(): QueryStatement
    {
        return new CreateTableStatement('column_books')
            ->primary('book_id')
            ->string('order')
            ->integer('writer_ref')
            ->integer('legacy_code')
            ->string('writer', default: '');
    }
}

#[Table('column_reviews')]
final class ColumnReview implements MigratesUp
{
    use IsDatabaseModel;

    #[Virtual]
    public string $name = 'column_reviews';

    #[Column('order')]
    public ?ColumnAuthor $author = null;

    public function up(): QueryStatement
    {
        return new CreateTableStatement('column_reviews')
            ->primary('id')
            ->integer('order', nullable: true);
    }
}

#[Table('column_tags')]
final class ColumnTag implements MigratesUp
{
    use IsDatabaseModel;

    #[Virtual]
    public string $name = 'column_tags';

    #[Uuid]
    #[Column('tag_id')]
    public PrimaryKey $tagId;

    #[Column('tag_name')]
    public string $tagName;

    public function up(): QueryStatement
    {
        return new CreateTableStatement('column_tags')
            ->uuid('tag_id')
            ->string('tag_name');
    }
}

#[Table('column_accounts')]
final class ColumnAccount implements MigratesUp
{
    use IsDatabaseModel;

    #[Virtual]
    public string $name = 'column_accounts';

    #[Column('order')]
    public PrimaryKey $accountId;

    #[Column('author_ref')]
    public ?int $authorId = null;

    #[HasOne(ownerJoin: 'author_id', relationJoin: 'author_ref')]
    public ColumnAuthor $author;

    public function up(): QueryStatement
    {
        return new CreateTableStatement('column_accounts')
            ->primary('order')
            ->integer('author_ref', nullable: true);
    }
}
