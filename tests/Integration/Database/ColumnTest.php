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
use Tempest\Mapper\Hidden;
use Tests\Tempest\Integration\FrameworkIntegrationTestCase;

use function Tempest\Database\query;
use function Tempest\Support\Random\is_uuid;

final class ColumnTest extends FrameworkIntegrationTestCase
{
    #[Test]
    public function renamed_properties_are_read_written_and_queried(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class);

        ColumnAuthor::create(displayName: 'Zoe');
        $other = ColumnAuthor::create(displayName: 'Ada');

        $this->assertSame('Ada', ColumnAuthor::select()->orderBy('displayName')->first()->displayName);

        $author = ColumnAuthor::select()->whereField('displayName', 'Zoe')->first();
        $this->assertNotNull($author);

        $author->displayName = 'Bea';
        $author->save();

        $this->assertSame('Bea', ColumnAuthor::select()->get($author->authorId)->displayName);

        $author->displayName = 'Unsaved';
        $this->assertSame('Bea', $author->refresh()->displayName);

        $author->delete();
        $this->assertNull(ColumnAuthor::select()->get($author->authorId));
        $this->assertSame('Ada', ColumnAuthor::get($other->authorId)->displayName);
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
    }

    #[Test]
    public function a_mapped_property_can_be_selected_alongside_an_expression_containing_a_comma(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class);

        $author = ColumnAuthor::create(displayName: 'Ada');

        $fields = ['displayName', "REPLACE(display_name, 'A', 'E') AS label"];
        $separate = query(ColumnAuthor::class)->select(...$fields)->build()->fetchFirst();
        $this->assertSame(['column_authors.displayName' => 'Ada', 'label' => 'Eda'], $separate);

        $combined = query(ColumnAuthor::class)->select(implode(', ', $fields));
        $this->assertSame($separate, $combined->build()->fetchFirst());
        $this->assertSame('Ada', $combined->get($author->authorId)->displayName);
    }

    #[Test]
    public function mapped_hidden_fields_can_be_included_and_virtual_fields_are_not_persisted(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class);

        $author = ColumnAuthor::new(displayName: 'Ada', internalNote: 'private', localNote: 'not stored')->save();

        $default = ColumnAuthor::select()->whereField('authorId', $author->authorId)->build()->fetchFirst();
        $this->assertArrayNotHasKey('column_authors.internalNote', $default);
        $this->assertArrayNotHasKey('column_authors.localNote', $default);
        $this->assertSame('private', ColumnAuthor::select()->include('internalNote')->get($author->authorId)->internalNote);
        $this->assertSame('private', query('column_authors')->select()->first()['internal_note']);
    }

    #[Test]
    public function grouped_filters_and_count_use_the_mapped_nullable_column(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class);

        $ada = ColumnAuthor::create(displayName: 'Ada', internalNote: 'match');
        ColumnAuthor::create(displayName: 'Bea', internalNote: 'match');
        ColumnAuthor::create(displayName: 'Ada');

        $rows = ColumnAuthor::select()->whereGroup(
            fn ($group) => $group
                ->whereField('internalNote', 'match')
                ->andWhere('displayName', 'Ada'),
        )->all();

        $this->assertSame([$ada->authorId->value], array_map(fn (ColumnAuthor $author) => $author->authorId->value, $rows));
        $this->assertSame(2, query(ColumnAuthor::class)->count('internalNote')->execute());
        $this->assertSame(1, query(ColumnAuthor::class)->count('internalNote')->distinct()->execute());
        $this->assertSame('Bea', ColumnAuthor::select()->whereRaw('display_name = ?', 'Bea')->orderBy('authorId')->first()->displayName);

        $group = query(ColumnAuthor::class)
            ->select('internal_note, COUNT(*) AS total')
            ->groupBy('internal_note')
            ->having('COUNT(*) = 2')
            ->build()
            ->fetchFirst();
        $this->assertSame('match', $group['internal_note'] ?? null);
    }

    #[Test]
    public function explicit_mapped_integer_keys_are_returned_but_not_updated(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class);

        $id = query(ColumnAuthor::class)->insert(authorId: new PrimaryKey(42), displayName: 'Ada')->execute();
        $this->assertSame(42, $id->value);

        query(ColumnAuthor::class)->update(authorId: new PrimaryKey(43), displayName: 'Bea')->whereField('authorId', $id)->execute();

        $rows = query('column_authors')->select()->all();
        $this->assertCount(1, $rows);
        $this->assertEquals(42, $rows[0]['author_id']);
        $this->assertSame('Bea', $rows[0]['display_name']);
    }

    #[Test]
    public function filtered_writes_and_aggregation_use_mapped_columns_and_database_defaults(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class, ColumnBook::class);

        $author = ColumnAuthor::create(displayName: 'Ada');
        $first = ColumnBook::create(bookTitle: 'First', author: $author, writer_ref: 2);
        $second = query(ColumnBook::class)->insert(bookTitle: 'Second', author: $author->authorId->value)->execute();

        query(ColumnBook::class)->update(writer_ref: 5)->whereField('bookTitle', 'First')->execute();

        $this->assertSame(8, ColumnBook::sum('writer_ref'));
        $this->assertSame(5, ColumnBook::get($first->bookId)->writer_ref);
        $this->assertSame(3, ColumnBook::get($second)->writer_ref);

        query(ColumnBook::class)->delete()->whereField('bookTitle', 'First')->execute();

        $this->assertNull(ColumnBook::get($first->bookId));
        $this->assertSame('Second', ColumnBook::get($second)->bookTitle);
    }

    #[Test]
    public function mapped_enum_values_serialize_on_insert_and_update(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnTag::class);

        $id = query(ColumnTag::class)->insert(tagName: 'news', tagState: ColumnTagState::PUBLISHED)->execute();
        $tag = ColumnTag::get($id);
        $this->assertSame('published', query('column_tags')->select()->whereField('tag_id', $id)->first()['tag_state']);
        $this->assertSame(ColumnTagState::PUBLISHED, $tag->tagState);

        $tag->update(tagState: ColumnTagState::DRAFT);

        $this->assertSame('draft', query('column_tags')->select()->whereField('tag_id', $id)->first()['tag_state']);
        $this->assertSame(ColumnTagState::DRAFT, ColumnTag::get($id)->tagState);
    }

    #[Test]
    public function object_inserts_generate_or_preserve_the_mapped_uuid(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnTag::class);

        $generated = ColumnTag::new(tagName: 'generated')->save();
        $explicit = 'e31f7c6c-3f00-4e9f-9500-4dd3a31a8301';
        $manual = ColumnTag::create(tagId: new PrimaryKey($explicit), tagName: 'explicit');

        $rows = query('column_tags')->select()->orderBy('tag_name')->all();
        $this->assertTrue(is_uuid($generated->tagId->value));
        $this->assertSame($explicit, $manual->tagId->value);
        $this->assertSame([$explicit, $generated->tagId->value], array_column($rows, 'tag_id'));
    }

    #[Test]
    public function mapped_uuid_batches_attach_children_to_their_own_parent_with_uninitialized_child_keys(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnTag::class, ColumnTagEntry::class);

        $first = ColumnTag::new(tagName: 'First', entries: [['entryName' => 'A']]);
        $second = ColumnTag::new(tagName: 'Second', entries: [['entryName' => 'B']]);
        query(ColumnTag::class)->insert($first, $second)->execute();

        $rows = query('column_tag_entries')->select()->orderBy('entry_name')->all();
        $this->assertNotSame($first->tagId->value, $second->tagId->value);
        $this->assertSame(['A', 'B'], array_column($rows, 'entry_name'));
        $this->assertSame([$first->tagId->value, $second->tagId->value], array_column($rows, 'tag_ref'));

        ColumnTagEntry::new(entryName: 'Without a primary key', tagRef: $first->tagId->value)->save();

        $this->assertSame(2, query('column_tag_entries')->count()->whereField('tag_ref', $first->tagId)->execute());
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
    public function self_joins_keep_two_mapped_foreign_keys_and_their_results_separate(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, ColumnAuthor::class);

        $mentor = ColumnAuthor::create(displayName: 'Mentor');
        $editor = ColumnAuthor::create(displayName: 'Editor');
        $author = ColumnAuthor::create(displayName: 'Ada', mentor: $mentor, editor: $editor);

        $loaded = ColumnAuthor::select()->with('mentor', 'editor')->orderBy('displayName')->first();
        $this->assertSame($author->authorId->value, $loaded->authorId->value);
        $this->assertSame('Mentor', $loaded->mentor->displayName);
        $this->assertSame('Editor', $loaded->editor->displayName);
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
        $empty = ColumnAuthor::create(displayName: 'Cora');
        $reviews = [ColumnReview::create(author: $author), ColumnReview::create(author: $author), ColumnReview::create(author: $other)];
        $unassigned = ColumnReview::create(author: null);

        $owners = ColumnAuthor::select()->whereHas('reviews')->orderBy('authorId')->all();
        $this->assertSame([$author->authorId->value, $other->authorId->value], array_map(fn (ColumnAuthor $owner) => $owner->authorId->value, $owners));
        $this->assertSame($empty->authorId->value, ColumnAuthor::select()->whereDoesntHave('reviews')->first()->authorId->value);
        $this->assertSame(
            array_map(fn (ColumnReview $review) => $review->id->value, $reviews),
            array_map(fn (ColumnReview $review) => $review->id->value, ColumnReview::select()->whereHas('author')->orderBy('id')->all()),
        );
        $this->assertSame($unassigned->id->value, ColumnReview::select()->whereDoesntHave('author')->first()->id->value);

        $qualified = ColumnAuthor::select()->whereHas('reviews', count: 2)->all();
        $this->assertSame(['Ada'], array_column($qualified, 'displayName'));
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

        ColumnAuthor::create(displayName: 'Decoy');
        $account = ColumnAccount::create(author: ['displayName' => 'Ada']);
        $author = ColumnAccount::select()->with('author')->get($account->accountId)->author;
        $empty = ColumnAccount::create();

        $this->assertSame('Ada', $author->displayName);
        $this->assertSame('Ada', $account->query('author')->select()->first()?->displayName);
        $this->assertNull($empty->query('author')->select()->first());
        $this->assertEquals([$account->accountId], array_column(ColumnAccount::select()->whereHas('author')->all(), 'accountId'));
        $this->assertEquals([$empty->accountId], array_column(ColumnAccount::select()->whereDoesntHave('author')->all(), 'accountId'));

        query(ColumnAccount::class)
            ->update(author: ['displayName' => 'Bea'])
            ->whereField('accountId', $account->accountId)
            ->execute();

        $loaded = ColumnAccount::select()->with('author')->get($account->accountId);

        $this->assertSame('Bea', $loaded->author->displayName);
        $this->assertSame($loaded->author->authorId->value, $loaded->authorId);
        $this->assertNull(ColumnAuthor::get($author->authorId));

        query(ColumnAccount::class)
            ->update(author: ['displayName' => 'Cora'])
            ->whereField('accountId', $empty->accountId)
            ->execute();

        $this->assertSame('Cora', ColumnAccount::select()->with('author')->get($empty->accountId)->author->displayName);
        $this->assertSame('Bea', ColumnAccount::select()->with('author')->get($account->accountId)->author->displayName);
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

    #[Hidden]
    #[Column('internal_note')]
    public ?string $internalNote = null;

    #[Virtual]
    #[Column('not_a_column')]
    public string $localNote = '';

    #[Column('mentor_ref')]
    public ?ColumnAuthor $mentor = null;

    #[Column('editor_ref')]
    public ?ColumnAuthor $editor = null;

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
            ->string('display_name')
            ->string('internal_note', nullable: true)
            ->integer('mentor_ref', nullable: true)
            ->integer('editor_ref', nullable: true);
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
            ->integer('legacy_code', default: 3)
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

    #[Column('tag_state')]
    public ColumnTagState $tagState = ColumnTagState::DRAFT;

    /** @var \Tests\Tempest\Integration\Database\ColumnTagEntry[] */
    #[HasMany(ownerJoin: 'tag_ref')]
    public array $entries = [];

    public function up(): QueryStatement
    {
        return new CreateTableStatement('column_tags')
            ->uuid('tag_id')
            ->string('tag_name')
            ->string('tag_state', default: 'draft');
    }
}

enum ColumnTagState: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
}

#[Table('column_tag_entries')]
final class ColumnTagEntry implements MigratesUp
{
    use IsDatabaseModel;

    #[Virtual]
    public string $name = 'column_tag_entries';

    #[Column('entry_name')]
    public string $entryName;

    #[Column('tag_ref')]
    public string $tagRef;

    public function up(): QueryStatement
    {
        return new CreateTableStatement('column_tag_entries')
            ->primary('id')
            ->string('entry_name')
            ->string('tag_ref', length: 36);
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
