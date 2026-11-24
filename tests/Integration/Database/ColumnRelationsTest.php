<?php

declare(strict_types=1);

namespace Tests\Tempest\Integration\Database;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tempest\Database\BelongsToMany;
use Tempest\Database\Builder\QueryBuilders\SelectQueryBuilder;
use Tempest\Database\Column;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\Exceptions\QueryWasInvalid;
use Tempest\Database\HasMany;
use Tempest\Database\HasManyThrough;
use Tempest\Database\HasOne;
use Tempest\Database\HasOneThrough;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\MigratesUp;
use Tempest\Database\Migrations\CreateMigrationsTable;
use Tempest\Database\PrimaryKey;
use Tempest\Database\QueryExecuted;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\Table;
use Tempest\Database\Virtual;
use Tempest\EventBus\EventBus;
use Tests\Tempest\Integration\FrameworkIntegrationTestCase;

use function Tempest\Database\inspect;
use function Tempest\Database\query;

final class ColumnRelationsTest extends FrameworkIntegrationTestCase
{
    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function has_many_batches_override_present_and_missing_foreign_keys(bool $replace): void
    {
        $this->database->migrate(CreateMigrationsTable::class, MappedOwner::class, MappedEntry::class);

        $other = MappedOwner::create(ownerName: 'Other', entries: [['entryName' => 'Keep']]);
        $entries = [
            MappedEntry::new(ownerRef: $other->ownerId->value, entryName: 'First'),
            MappedEntry::new(entryName: 'Second'),
        ];

        if ($replace) {
            $owner = MappedOwner::create(ownerName: 'Owner', entries: [['entryName' => 'Remove']]);
            $owner->update(entries: $entries);
        } else {
            $owner = MappedOwner::create(ownerName: 'Owner', entries: $entries);
        }

        $rows = query('mapped_entries')->select()->orderBy('entry_name')->all();
        $this->assertSame(['First', 'Keep', 'Second'], array_column($rows, 'entry_name'));
        $this->assertEquals(
            [$owner->ownerId->value, $other->ownerId->value, $owner->ownerId->value],
            array_column($rows, 'mapped_owner_owner_id'),
        );
    }

    #[Test]
    public function inferred_has_one_foreign_key_is_used_for_insert_and_replacement(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, MappedOwner::class, MappedEntry::class);

        $owner = MappedOwner::create(ownerName: 'Owner', entry: ['entryName' => 'Old']);
        $old = MappedOwner::select()->with('entry')->get($owner->ownerId)->entry;
        $this->assertSame('Old', $old->entryName);

        $other = MappedOwner::create(ownerName: 'Other', entry: ['entryName' => 'Keep']);
        $owner->update(entry: MappedEntry::new(entryName: 'New'));

        $this->assertNull(MappedEntry::get($old->entryId));
        $this->assertSame('New', $owner->query('entry')->select()->first()->entryName);
        $this->assertSame('Keep', MappedOwner::select()->with('entry')->get($other->ownerId)->entry->entryName);

        $row = query('mapped_entries')->select()->whereField('entry_name', 'New')->first();
        $this->assertEquals($owner->ownerId->value, $row['mapped_owner_owner_id']);
    }

    #[Test]
    public function a_failed_replacement_rolls_back_the_deleted_children_inside_a_transaction(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, MappedOwner::class, MappedEntry::class);

        $owner = MappedOwner::create(ownerName: 'Owner', entries: [['entryName' => 'Keep']]);
        $before = query('mapped_entries')->select()->all();
        $writes = [];
        $failure = null;

        $this->container->get(EventBus::class)->listen(function (QueryExecuted $event) use (&$writes): void {
            if ($event->isDelete() || $event->isInsert()) {
                $writes[] = [$event->queryType, $event->failed];
            }
        });

        $committed = $this->container->get(Database::class)->withinTransaction(function () use ($owner, &$failure): void {
            try {
                query(MappedOwner::class)
                    ->update(entries: [['entryName' => null]])
                    ->whereField('ownerId', $owner->ownerId)
                    ->execute();
            } catch (QueryWasInvalid $exception) {
                $failure = $exception;

                throw $exception;
            }
        });

        $this->assertFalse($committed);
        $this->assertSame([['DELETE', false], ['INSERT', true]], $writes);
        $this->assertInstanceOf(QueryWasInvalid::class, $failure);
        $this->assertContains($failure->pdoException->getCode(), ['23000', '23502']);
        $this->assertStringContainsString('entry_name', $failure->pdoException->getMessage());
        $this->assertSame($before, query('mapped_entries')->select()->all());
    }

    #[Test]
    public function an_inferred_belongs_to_uses_the_mapped_target_key_and_can_be_cleared(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, MappedOwner::class, MappedEntry::class, MappedItem::class);

        $owner = MappedOwner::create(ownerName: 'Owner', entries: [['entryName' => 'Entry']]);
        $entry = $owner->query('entries')->select()->first();
        $item = MappedItem::create(itemName: 'Item', entry: $entry);

        $row = query('mapped_items')->select()->whereField('item_id', $item->itemId)->first();
        $this->assertEquals($entry->entryId->value, $row['entry_entry_id']);
        $this->assertSame(
            'Entry',
            MappedItem::select()
                ->with('entry')
                ->whereHas('entry')
                ->get($item->itemId)
                ->entry
                ->entryName,
        );

        $item->update(entry: null);

        $this->assertNull($item->query('entry')->select()->first());
        $this->assertNull(MappedItem::select()->with('entry')->whereDoesntHave('entry')->get($item->itemId)->entry);

        $item->update(entry: MappedEntry::new(ownerRef: $owner->ownerId->value, entryName: 'New entry'));

        $reloaded = MappedItem::select()->with('entry')->get($item->itemId);
        $this->assertSame('New entry', $reloaded->entry?->entryName);
    }

    #[Test]
    #[TestWith(['items', false, false])]
    #[TestWith(['firstItem', true, false])]
    #[TestWith(['customItems', false, true])]
    #[TestWith(['customFirstItem', true, true])]
    public function through_relations_use_mapped_keys_for_loading_scoping_and_existence(string $relation, bool $single, bool $custom): void
    {
        $this->database->migrate(CreateMigrationsTable::class, MappedOwner::class, MappedEntry::class, MappedItem::class);

        $owner = MappedOwner::create(ownerName: 'Owner', entries: [['entryName' => 'Entry']]);
        $other = MappedOwner::create(ownerName: 'Other', entries: [['entryName' => 'No target']]);
        $entry = $owner->query('entries')->select()->first();
        $joinColumn = $custom ? 'custom_entry' : 'mapped_entry_entry_id';
        $names = $single ? ['Item'] : ['Item', 'Second'];

        foreach ($names as $name) {
            query('mapped_items')->insert(...['item_name' => $name, $joinColumn => $entry->entryId->value])->execute();
        }

        if ($custom) {
            foreach ([$owner, $other] as $parent) {
                query('mapped_entries')
                    ->update(custom_owner: $parent->ownerId->value)
                    ->whereField('mapped_owner_owner_id', $parent->ownerId)
                    ->execute();
            }
        }

        $with = MappedOwner::select()->with($relation)->get($owner->ownerId)->{$relation};
        $empty = MappedOwner::select()->with($relation)->get($other->ownerId)->{$relation};
        $this->assertEqualsCanonicalizing($names, $single ? [$with->itemName] : array_column($with, 'itemName'));
        $this->assertSame($single ? null : [], $empty);
        $this->assertSame($names, array_column($owner->query($relation)->select()->orderBy('itemName')->all(), 'itemName'));
        $this->assertSame(
            'Owner',
            MappedOwner::select()
                ->whereHas($relation, function (SelectQueryBuilder $query): void {
                    $query->whereField('itemName', 'Item');
                })
                ->first()
                ->ownerName,
        );
        $this->assertSame(['Owner'], array_column(MappedOwner::select()->whereHas($relation)->all(), 'ownerName'));
        $this->assertSame(['Other'], array_column(MappedOwner::select()->whereDoesntHave($relation)->all(), 'ownerName'));
    }

    #[Test]
    #[TestWith(['connectedItems'])]
    #[TestWith(['connectedItem'])]
    public function self_referencing_through_filters_match_the_correct_owner(string $relation): void
    {
        $this->database->migrate(CreateMigrationsTable::class, MappedItem::class, MappedEntry::class);

        $owner = MappedItem::create(itemName: 'Owner');
        $other = MappedItem::create(itemName: 'Other');
        $entry = MappedEntry::create(ownerRef: $owner->itemId->value, entryName: 'With targets');
        MappedEntry::create(ownerRef: $other->itemId->value, entryName: 'Without targets');

        query('mapped_items')
            ->insert(
                ['item_name' => 'Target A', 'mapped_entry_entry_id' => $entry->entryId],
                ['item_name' => 'Target B', 'mapped_entry_entry_id' => $entry->entryId],
            )
            ->execute();

        $this->assertSame(['Owner'], array_column(MappedItem::select()->whereHas($relation)->all(), 'itemName'));
        $without = MappedItem::select()->whereDoesntHave($relation)->orderBy('itemName')->all();
        $this->assertSame(['Other', 'Target A', 'Target B'], array_column($without, 'itemName'));

        $matching = MappedItem::select()->whereHas(
            relation: $relation,
            callback: function (SelectQueryBuilder $query): void {
                $query->whereField('itemName', 'Target B');
            },
            operator: '=',
            count: 1,
        )->all();

        $this->assertSame(['Owner'], array_column($matching, 'itemName'));

        if ($relation === 'connectedItems') {
            $this->assertSame(['Owner'], array_column(MappedItem::select()->whereHas($relation, count: 2)->all(), 'itemName'));
        }
    }

    #[Test]
    public function self_referencing_pivot_filters_match_only_the_owners_of_existing_targets(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, MappedItem::class, MappedPivot::class);

        $target = MappedItem::create(itemName: 'Target');
        $owner = MappedItem::create(itemName: 'Owner', peers: [$target]);
        $other = MappedItem::create(itemName: 'Other');

        query('mapped_pivot')
            ->insert(
                ['mapped_item_item_id' => $owner->itemId, 'mapped_owner_owner_id' => 999],
                ['mapped_item_item_id' => $other->itemId, 'mapped_owner_owner_id' => 999],
            )
            ->execute();

        $this->assertSame(['Owner'], array_column(MappedItem::select()->whereHas('peers')->all(), 'itemName'));
        $this->assertSame(['Other', 'Target'], array_column(MappedItem::select()->whereDoesntHave('peers')->orderBy('itemName')->all(), 'itemName'));
        $this->assertSame([], MappedItem::select()->whereHas('peers', count: 2)->all());

        $matches = MappedItem::select()->whereHas('peers', function (SelectQueryBuilder $query): void {
            $query->whereField('itemName', 'Target');
        })->all();

        $this->assertSame(['Owner'], array_column($matches, 'itemName'));

        $nonMatching = MappedItem::select()->whereGroup(function ($group): void {
            $group->whereHas('peers', function (SelectQueryBuilder $query): void {
                $query->whereField('itemName', 'Nobody')->orWhere('itemName', 'Other');
            });
        })->all();

        $this->assertSame([], $nonMatching);
    }

    #[Test]
    public function self_referencing_pivot_compiles_a_materialized_mysql_target_subquery(): void
    {
        $statement = inspect(MappedItem::class)
            ->getRelation('peers')
            ->getExistsStatement();

        $this->assertSame(
            'EXISTS (SELECT 1 FROM `mapped_pivot` WHERE `mapped_pivot`.`mapped_item_item_id` = `mapped_items`.`item_id` AND EXISTS (SELECT 1 FROM (SELECT /*+ NO_MERGE() */ * FROM `mapped_items`) AS `target_table` WHERE `target_table`.`item_id` = `mapped_pivot`.`mapped_owner_owner_id`))',
            $statement->compile(DatabaseDialect::MYSQL),
        );
    }

    #[Test]
    public function self_referencing_pivot_filters_work_for_updates_and_deletes(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, MappedItem::class, MappedPivot::class);

        $target = MappedItem::create(itemName: 'Target');
        $owner = MappedItem::create(itemName: 'Owner', peers: [$target]);
        MappedItem::create(itemName: 'Other');

        query(MappedItem::class)
            ->update(itemName: 'Updated')
            ->whereHas('peers')
            ->execute();

        query(MappedItem::class)
            ->delete()
            ->whereDoesntHave('peers')
            ->execute();

        $this->assertSame(['Updated'], array_column(MappedItem::select()->all(), 'itemName'));
        $this->assertSame($owner->itemId->value, MappedItem::select()->first()->itemId->value);
    }

    #[Test]
    public function pivot_synchronization_uses_both_mapped_keys_without_touching_other_owners(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, MappedOwner::class, MappedEntry::class, MappedItem::class, MappedPivot::class);

        $first = MappedItem::create(itemName: 'First');
        $second = MappedItem::create(itemName: 'Second');
        $owner = MappedOwner::create(ownerName: 'Owner', links: [$first]);
        $other = MappedOwner::create(ownerName: 'Other', links: [$first]);

        $this->assertSame(['First'], array_column(MappedOwner::select()->with('links')->get($owner->ownerId)->links, 'itemName'));

        $owner->update(links: [$second]);

        $this->assertSame(['Second'], array_column($owner->query('links')->select()->all(), 'itemName'));
        $this->assertSame(
            'Owner',
            MappedOwner::select()
                ->whereHas('links', function (SelectQueryBuilder $query): void {
                    $query->whereField('itemName', 'Second');
                })
                ->first()
                ->ownerName,
        );

        $owner->update(links: []);

        $this->assertSame('Owner', MappedOwner::select()->whereDoesntHave('links')->first()->ownerName);

        $pivot = query('mapped_pivot')->select()->all();
        $this->assertCount(1, $pivot);
        $this->assertEquals($other->ownerId->value, $pivot[0]['mapped_owner_owner_id']);
        $this->assertEquals($first->itemId->value, $pivot[0]['mapped_item_item_id']);
    }
}

#[Table('mapped_owners')]
final class MappedOwner implements MigratesUp
{
    use IsDatabaseModel;

    #[Virtual]
    public string $name = 'mapped_owners';

    #[Column('owner_id')]
    public PrimaryKey $ownerId;

    #[Column('owner_name')]
    public string $ownerName;

    /** @var \Tests\Tempest\Integration\Database\MappedEntry[] */
    #[HasMany]
    public array $entries = [];

    #[HasOne]
    public ?MappedEntry $entry = null;

    /** @var \Tests\Tempest\Integration\Database\MappedItem[] */
    #[HasManyThrough(through: MappedEntry::class)]
    public array $items = [];

    #[HasOneThrough(through: MappedEntry::class)]
    public ?MappedItem $firstItem = null;

    /** @var \Tests\Tempest\Integration\Database\MappedItem[] */
    #[HasManyThrough(through: MappedEntry::class, ownerJoin: 'custom_owner', throughOwnerJoin: 'custom_entry')]
    public array $customItems = [];

    #[HasOneThrough(through: MappedEntry::class, ownerJoin: 'custom_owner', throughOwnerJoin: 'custom_entry')]
    public ?MappedItem $customFirstItem = null;

    /** @var \Tests\Tempest\Integration\Database\MappedItem[] */
    #[BelongsToMany(pivot: 'mapped_pivot')]
    public array $links = [];

    public function up(): QueryStatement
    {
        return new CreateTableStatement('mapped_owners')
            ->primary('owner_id')
            ->string('owner_name');
    }
}

#[Table('mapped_entries')]
final class MappedEntry implements MigratesUp
{
    use IsDatabaseModel;

    #[Virtual]
    public string $name = 'mapped_entries';

    #[Column('entry_id')]
    public PrimaryKey $entryId;

    #[Column('mapped_owner_owner_id')]
    public int $ownerRef;

    #[Column('entry_name')]
    public string $entryName;

    public function up(): QueryStatement
    {
        return new CreateTableStatement('mapped_entries')
            ->primary('entry_id')
            ->integer('mapped_owner_owner_id')
            ->integer('custom_owner', nullable: true)
            ->string('entry_name');
    }
}

#[Table('mapped_items')]
final class MappedItem implements MigratesUp
{
    use IsDatabaseModel;

    #[Virtual]
    public string $name = 'mapped_items';

    #[Column('item_id')]
    public PrimaryKey $itemId;

    #[Column('item_name')]
    public string $itemName;

    public ?MappedEntry $entry = null;

    /** @var \Tests\Tempest\Integration\Database\MappedItem[] */
    #[BelongsToMany(pivot: 'mapped_pivot', relatedOwnerJoin: 'mapped_owner_owner_id')]
    public array $peers = [];

    /** @var \Tests\Tempest\Integration\Database\MappedItem[] */
    #[HasManyThrough(through: MappedEntry::class, ownerJoin: 'mapped_owner_owner_id')]
    public array $connectedItems = [];

    #[HasOneThrough(through: MappedEntry::class, ownerJoin: 'mapped_owner_owner_id')]
    public ?MappedItem $connectedItem = null;

    public function up(): QueryStatement
    {
        return new CreateTableStatement('mapped_items')
            ->primary('item_id')
            ->string('item_name')
            ->integer('entry_entry_id', nullable: true)
            ->integer('custom_entry', nullable: true)
            ->integer('mapped_entry_entry_id', nullable: true);
    }
}

final class MappedPivot implements MigratesUp
{
    public string $name = 'mapped_pivot';

    public function up(): QueryStatement
    {
        return new CreateTableStatement('mapped_pivot')
            ->integer('mapped_owner_owner_id')
            ->integer('mapped_item_item_id');
    }
}
