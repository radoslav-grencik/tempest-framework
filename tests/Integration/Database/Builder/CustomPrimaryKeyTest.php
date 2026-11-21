<?php

namespace Tests\Tempest\Integration\Database\Builder;

use PHPUnit\Framework\Attributes\Test;
use Tempest\Database\Exceptions\ModelHasAmbiguousPrimaryKey;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\MigratesUp;
use Tempest\Database\Migrations\CreateMigrationsTable;
use Tempest\Database\PrimaryKey;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\Table;
use Tests\Tempest\Integration\FrameworkIntegrationTestCase;

use function Tempest\Database\inspect;
use function Tempest\Database\query;

final class CustomPrimaryKeyTest extends FrameworkIntegrationTestCase
{
    #[Test]
    public function model_with_custom_primary_key_name(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, CreateCustomPrimaryKeyUserModelTable::class);

        $frieren = query(CustomPrimaryKeyUserModel::class)->create(name: 'Frieren', magic: 'Time Magic');

        $this->assertInstanceOf(CustomPrimaryKeyUserModel::class, $frieren);
        $this->assertInstanceOf(PrimaryKey::class, $frieren->uuid);
        $this->assertSame('Frieren', $frieren->name);
        $this->assertSame('Time Magic', $frieren->magic);

        $retrieved = query(CustomPrimaryKeyUserModel::class)->get($frieren->uuid);
        $this->assertNotNull($retrieved);
        $this->assertSame('Frieren', $retrieved->name);
        $this->assertTrue($frieren->uuid->equals($retrieved->uuid));
    }

    #[Test]
    public function a_trait_added_by_the_child_takes_precedence_over_an_inherited_key(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, CreateAccountsWithCustomKeyTable::class);

        $model = new ActiveRecordWithLocalId();
        $model->accountId = new PrimaryKey(99);
        $model->save();

        $retrieved = ActiveRecordWithLocalId::get($model->id);

        $this->assertInstanceOf(ActiveRecordWithLocalId::class, $retrieved);
        $this->assertSame($model->id->value, $retrieved->id->value);
        $this->assertFalse(isset($retrieved->accountId));
    }

    #[Test]
    public function a_child_can_replace_a_key_without_using_the_trait(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, CreateAccountsWithCustomKeyTable::class);

        $model = query(ModelWithCustomerKey::class)->create(accountId: new PrimaryKey(99));
        $retrieved = query(ModelWithCustomerKey::class)->get($model->customerId);

        $this->assertInstanceOf(ModelWithCustomerKey::class, $retrieved);
        $this->assertFalse(isset($retrieved->accountId));
        $this->assertSame('accountId', inspect(ModelWithAccountKey::class)->getPrimaryKey());
    }

    #[Test]
    public function a_static_key_property_is_not_persisted(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, CreateAccountsWithCustomKeyTable::class);

        ActiveRecordWithLocalId::$scratch = new PrimaryKey(99);

        $account = ActiveRecordWithLocalId::create();

        $this->assertInstanceOf(ActiveRecordWithLocalId::class, ActiveRecordWithLocalId::get($account->id));
    }

    #[Test]
    public function a_nullable_foreign_key_declared_on_an_abstract_base_is_persisted(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, CreateAccountsWithCustomKeyTable::class);

        $owner = ActiveRecordWithLocalId::create();
        $account = query(ModelWithCustomerKey::class)->create(ownerId: $owner->id);
        $retrieved = query(ModelWithCustomerKey::class)->get($account->customerId);

        $this->assertInstanceOf(ModelWithCustomerKey::class, $retrieved);
        $this->assertSame($owner->id->value, $retrieved->ownerId?->value);
    }

    #[Test]
    public function ambiguous_keys_are_rejected_before_inserting(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, CreateAccountsWithCustomKeyTable::class);

        try {
            query(ModelWithMultipleIds::class)->insert(name: 'test')->execute();
            $this->fail('Expected ambiguous primary keys to be rejected.');
        } catch (ModelHasAmbiguousPrimaryKey $exception) {
            $this->assertStringContainsString(ModelWithMultipleIds::class, $exception->getMessage());
            $this->assertStringContainsString('uuid, external_id', $exception->getMessage());
            $this->assertSame(0, query('accounts_with_local_id')->count()->execute());
        }
    }

    #[Test]
    public function update_or_create_with_custom_primary_key(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, CreateCustomPrimaryKeyUserModelTable::class);

        $frieren = query(CustomPrimaryKeyUserModel::class)->create(name: 'Frieren', magic: 'Time Magic');

        $updated = query(CustomPrimaryKeyUserModel::class)->updateOrCreate(
            find: ['name' => 'Frieren'],
            update: ['magic' => 'Advanced Time Magic'],
        );

        $this->assertTrue($frieren->uuid->equals($updated->uuid));
        $this->assertSame('Advanced Time Magic', $updated->magic);
    }

    #[Test]
    public function model_without_id_property_still_works(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, CreateModelWithoutIdMigration::class);

        $model = query(ModelWithoutId::class)->new(name: 'Test');

        $this->assertInstanceOf(ModelWithoutId::class, $model);
        $this->assertSame('Test', $model->name);
    }
}

abstract class ModelWithOwnerReference
{
    public ?PrimaryKey $ownerId = null;
}

class ModelWithAccountKey extends ModelWithOwnerReference
{
    public PrimaryKey $accountId;

    public string $name = 'Frieren';
}

#[Table('accounts_with_local_id')]
final class ActiveRecordWithLocalId extends ModelWithAccountKey
{
    use IsDatabaseModel;

    public static PrimaryKey $scratch;
}

#[Table('accounts_with_custom_key')]
final class ModelWithCustomerKey extends ModelWithAccountKey
{
    public PrimaryKey $customerId;
}

final class CreateAccountsWithCustomKeyTable implements MigratesUp
{
    public string $name = '003_create_accounts_with_custom_key';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            CreateTableStatement::forModel(ActiveRecordWithLocalId::class)->primary()->text('name')->integer('ownerId', nullable: true),
            CreateTableStatement::forModel(ModelWithCustomerKey::class)
                ->primary('customerId')
                ->text('name')
                ->foreignId('ownerId', constrainedOn: 'accounts_with_local_id.id', nullable: true),
        );
    }
}

final class CustomPrimaryKeyUserModel
{
    public ?PrimaryKey $uuid = null;

    public function __construct(
        public string $name,
        public string $magic,
    ) {}
}

#[Table('accounts_with_local_id')]
final class ModelWithMultipleIds
{
    public ?PrimaryKey $uuid = null;

    public ?PrimaryKey $external_id = null;

    public function __construct(
        public string $name = 'test',
    ) {}
}

final class ModelWithoutId
{
    public function __construct(
        public string $name,
    ) {}
}

final class CreateCustomPrimaryKeyUserModelTable implements MigratesUp
{
    public string $name = '001_create_user_model';

    public function up(): QueryStatement
    {
        return CreateTableStatement::forModel(CustomPrimaryKeyUserModel::class)
            ->primary(name: 'uuid')
            ->text('name')
            ->text('magic');
    }
}

final class CreateModelWithoutIdMigration implements MigratesUp
{
    public string $name = '002_create_model_without_id';

    public function up(): QueryStatement
    {
        return CreateTableStatement::forModel(ModelWithoutId::class)
            ->text('name');
    }
}
