<?php

declare(strict_types=1);

namespace Tests\Tempest\Integration\Database;

use PHPUnit\Framework\Attributes\Test;
use Tempest\Database\Column;
use Tempest\Database\HasMany;
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
    public function renamed_keys_and_fields_work_in_eager_and_scoped_relations(): void
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

    #[Column('book_title')]
    public string $bookTitle;

    #[Column('writer_ref')]
    public ColumnAuthor $author;

    public function up(): QueryStatement
    {
        return new CreateTableStatement('column_books')
            ->primary('book_id')
            ->string('book_title')
            ->integer('writer_ref');
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
