<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests;

use DateTime;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Illuminate\Testing\Assert;
use Illuminate\Tests\Database\DatabaseQueryBuilderTest;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Cursor;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use MongoDB\Laravel\Collection;
use MongoDB\Laravel\Query\Builder;
use MongoDB\Laravel\Tests\Models\Item;
use MongoDB\Laravel\Tests\Models\User;
use Stringable;

use function count;
use function key;
use function md5;
use function sort;
use function strlen;
use function strtotime;

class QueryBuilderTest extends TestCase
{
    public function tearDown(): void
    {
        DB::table('users')->truncate();
        DB::table('items')->truncate();
    }

    public function testDeleteWithId()
    {
        $user = DB::table('users')->insertGetId([
            ['name' => 'Jane Doe', 'age' => 20],
        ]);

        $userId = (string) $user;

        DB::table('items')->insert([
            ['name' => 'one thing', 'user_id' => $userId],
            ['name' => 'last thing', 'user_id' => $userId],
            ['name' => 'another thing', 'user_id' => $userId],
            ['name' => 'one more thing', 'user_id' => $userId],
        ]);

        $product = DB::table('items')->first();

        $pid = (string) ($product['_id']);

        DB::table('items')->where('user_id', $userId)->delete($pid);

        $this->assertEquals(3, DB::table('items')->count());

        $product = DB::table('items')->first();

        $pid = $product['_id'];

        DB::table('items')->where('user_id', $userId)->delete($pid);

        DB::table('items')->where('user_id', $userId)->delete(md5('random-id'));

        $this->assertEquals(2, DB::table('items')->count());
    }

    public function testCollection()
    {
        $this->assertInstanceOf(Builder::class, DB::table('users'));
    }

    public function testGet()
    {
        $users = DB::table('users')->get();
        $this->assertCount(0, $users);

        DB::table('users')->insert(['name' => 'John Doe']);

        $users = DB::table('users')->get();
        $this->assertCount(1, $users);
    }

    public function testNoDocument()
    {
        $items = DB::table('items')->where('name', 'nothing')->get()->toArray();
        $this->assertEquals([], $items);

        $item = DB::table('items')->where('name', 'nothing')->first();
        $this->assertNull($item);

        $item = DB::table('items')->where('_id', '51c33d8981fec6813e00000a')->first();
        $this->assertNull($item);
    }

    public function testInsert()
    {
        DB::table('users')->insert([
            'tags' => ['tag1', 'tag2'],
            'name' => 'John Doe',
        ]);

        $users = DB::table('users')->get();
        $this->assertCount(1, $users);

        $user = $users[0];
        $this->assertEquals('John Doe', $user['name']);
        $this->assertIsArray($user['tags']);
    }

    public function testInsertGetId()
    {
        $id = DB::table('users')->insertGetId(['name' => 'John Doe']);
        $this->assertInstanceOf(ObjectId::class, $id);
    }

    public function testBatchInsert()
    {
        DB::table('users')->insert([
            [
                'tags' => ['tag1', 'tag2'],
                'name' => 'Jane Doe',
            ],
            [
                'tags' => ['tag3'],
                'name' => 'John Doe',
            ],
        ]);

        $users = DB::table('users')->get();
        $this->assertCount(2, $users);
        $this->assertIsArray($users[0]['tags']);
    }

    public function testFind()
    {
        $id = DB::table('users')->insertGetId(['name' => 'John Doe']);

        $user = DB::table('users')->find($id);
        $this->assertEquals('John Doe', $user['name']);
    }

    public function testFindWithTimeout()
    {
        $id = DB::table('users')->insertGetId(['name' => 'John Doe']);

        $subscriber = new class implements CommandSubscriber {
            public function commandStarted(CommandStartedEvent $event)
            {
                if ($event->getCommandName() !== 'find') {
                    return;
                }

                // Expect the timeout to be converted to milliseconds
                Assert::assertSame(1000, $event->getCommand()->maxTimeMS);
            }

            public function commandFailed(CommandFailedEvent $event)
            {
            }

            public function commandSucceeded(CommandSucceededEvent $event)
            {
            }
        };

        DB::getMongoClient()->getManager()->addSubscriber($subscriber);
        try {
            DB::table('users')->timeout(1)->find($id);
        } finally {
            DB::getMongoClient()->getManager()->removeSubscriber($subscriber);
        }
    }

    public function testFindNull()
    {
        $user = DB::table('users')->find(null);
        $this->assertNull($user);
    }

    public function testCount()
    {
        DB::table('users')->insert([
            ['name' => 'Jane Doe'],
            ['name' => 'John Doe'],
        ]);

        $this->assertEquals(2, DB::table('users')->count());
    }

    public function testUpdate()
    {
        DB::table('users')->insert([
            ['name' => 'Jane Doe', 'age' => 20],
            ['name' => 'John Doe', 'age' => 21],
        ]);

        DB::table('users')->where('name', 'John Doe')->update(['age' => 100]);

        $john = DB::table('users')->where('name', 'John Doe')->first();
        $jane = DB::table('users')->where('name', 'Jane Doe')->first();
        $this->assertEquals(100, $john['age']);
        $this->assertEquals(20, $jane['age']);
    }

    public function testUpdateOperators()
    {
        DB::table('users')->insert([
            ['name' => 'Jane Doe', 'age' => 20],
            ['name' => 'John Doe', 'age' => 19],
        ]);

        DB::table('users')->where('name', 'John Doe')->update(
            [
                '$unset' => ['age' => 1],
                'ageless' => true,
            ],
        );
        DB::table('users')->where('name', 'Jane Doe')->update(
            [
                '$inc' => ['age' => 1],
                '$set' => ['pronoun' => 'she'],
                'ageless' => false,
            ],
        );

        $john = DB::table('users')->where('name', 'John Doe')->first();
        $jane = DB::table('users')->where('name', 'Jane Doe')->first();

        $this->assertArrayNotHasKey('age', $john);
        $this->assertTrue($john['ageless']);

        $this->assertEquals(21, $jane['age']);
        $this->assertEquals('she', $jane['pronoun']);
        $this->assertFalse($jane['ageless']);
    }

    public function testDelete()
    {
        DB::table('users')->insert([
            ['name' => 'Jane Doe', 'age' => 20],
            ['name' => 'John Doe', 'age' => 25],
        ]);

        DB::table('users')->where('age', '<', 10)->delete();
        $this->assertEquals(2, DB::table('users')->count());

        DB::table('users')->where('age', '<', 25)->delete();
        $this->assertEquals(1, DB::table('users')->count());
    }

    public function testTruncate()
    {
        DB::table('users')->insert(['name' => 'John Doe']);
        DB::table('users')->insert(['name' => 'John Doe']);
        $this->assertEquals(2, DB::table('users')->count());
        $result = DB::table('users')->truncate();
        $this->assertTrue($result);
        $this->assertEquals(0, DB::table('users')->count());
    }

    public function testSubKey()
    {
        DB::table('users')->insert([
            [
                'name' => 'John Doe',
                'address' => ['country' => 'Belgium', 'city' => 'Ghent'],
            ],
            [
                'name' => 'Jane Doe',
                'address' => ['country' => 'France', 'city' => 'Paris'],
            ],
        ]);

        $users = DB::table('users')->where('address.country', 'Belgium')->get();
        $this->assertCount(1, $users);
        $this->assertEquals('John Doe', $users[0]['name']);
    }

    public function testInArray()
    {
        DB::table('items')->insert([
            [
                'tags' => ['tag1', 'tag2', 'tag3', 'tag4'],
            ],
            [
                'tags' => ['tag2'],
            ],
        ]);

        $items = DB::table('items')->where('tags', 'tag2')->get();
        $this->assertCount(2, $items);

        $items = DB::table('items')->where('tags', 'tag1')->get();
        $this->assertCount(1, $items);
    }

    public function testRaw()
    {
        DB::table('users')->insert([
            ['name' => 'Jane Doe', 'age' => 20],
            ['name' => 'John Doe', 'age' => 25],
        ]);

        $cursor = DB::table('users')->raw(function ($collection) {
            return $collection->find(['age' => 20]);
        });

        $this->assertInstanceOf(Cursor::class, $cursor);
        $this->assertCount(1, $cursor->toArray());

        $collection = DB::table('users')->raw();
        $this->assertInstanceOf(Collection::class, $collection);

        $collection = User::raw();
        $this->assertInstanceOf(Collection::class, $collection);

        $results = DB::table('users')->whereRaw(['age' => 20])->get();
        $this->assertCount(1, $results);
        $this->assertEquals('Jane Doe', $results[0]['name']);
    }

    public function testPush()
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'John Doe',
            'tags' => [],
            'messages' => [],
        ]);

        DB::table('users')->where('_id', $id)->push('tags', 'tag1');

        $user = DB::table('users')->find($id);
        $this->assertIsArray($user['tags']);
        $this->assertCount(1, $user['tags']);
        $this->assertEquals('tag1', $user['tags'][0]);

        DB::table('users')->where('_id', $id)->push('tags', 'tag2');
        $user = DB::table('users')->find($id);
        $this->assertCount(2, $user['tags']);
        $this->assertEquals('tag2', $user['tags'][1]);

        // Add duplicate
        DB::table('users')->where('_id', $id)->push('tags', 'tag2');
        $user = DB::table('users')->find($id);
        $this->assertCount(3, $user['tags']);

        // Add unique
        DB::table('users')->where('_id', $id)->push('tags', 'tag1', true);
        $user = DB::table('users')->find($id);
        $this->assertCount(3, $user['tags']);

        $message = ['from' => 'Jane', 'body' => 'Hi John'];
        DB::table('users')->where('_id', $id)->push('messages', $message);
        $user = DB::table('users')->find($id);
        $this->assertIsArray($user['messages']);
        $this->assertCount(1, $user['messages']);
        $this->assertEquals($message, $user['messages'][0]);

        // Raw
        DB::table('users')->where('_id', $id)->push([
            'tags' => 'tag3',
            'messages' => ['from' => 'Mark', 'body' => 'Hi John'],
        ]);
        $user = DB::table('users')->find($id);
        $this->assertCount(4, $user['tags']);
        $this->assertCount(2, $user['messages']);

        DB::table('users')->where('_id', $id)->push([
            'messages' => [
                'date' => new DateTime(),
                'body' => 'Hi John',
            ],
        ]);
        $user = DB::table('users')->find($id);
        $this->assertCount(3, $user['messages']);
    }

    public function testPushRefuses2ndArgumentWhen1stIsAnArray()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('2nd argument of MongoDB\Laravel\Query\Builder::push() must be "null" when 1st argument is an array. Got "string" instead.');

        DB::table('users')->push(['tags' => 'tag1'], 'tag2');
    }

    public function testPull()
    {
        $message1 = ['from' => 'Jane', 'body' => 'Hi John'];
        $message2 = ['from' => 'Mark', 'body' => 'Hi John'];

        $id = DB::table('users')->insertGetId([
            'name' => 'John Doe',
            'tags' => ['tag1', 'tag2', 'tag3', 'tag4'],
            'messages' => [$message1, $message2],
        ]);

        DB::table('users')->where('_id', $id)->pull('tags', 'tag3');

        $user = DB::table('users')->find($id);
        $this->assertIsArray($user['tags']);
        $this->assertCount(3, $user['tags']);
        $this->assertEquals('tag4', $user['tags'][2]);

        DB::table('users')->where('_id', $id)->pull('messages', $message1);

        $user = DB::table('users')->find($id);
        $this->assertIsArray($user['messages']);
        $this->assertCount(1, $user['messages']);

        // Raw
        DB::table('users')->where('_id', $id)->pull(['tags' => 'tag2', 'messages' => $message2]);
        $user = DB::table('users')->find($id);
        $this->assertCount(2, $user['tags']);
        $this->assertCount(0, $user['messages']);
    }

    public function testDistinct()
    {
        DB::table('items')->insert([
            ['name' => 'knife', 'type' => 'sharp'],
            ['name' => 'fork', 'type' => 'sharp'],
            ['name' => 'spoon', 'type' => 'round'],
            ['name' => 'spoon', 'type' => 'round'],
        ]);

        $items = DB::table('items')->distinct('name')->get()->toArray();
        sort($items);
        $this->assertCount(3, $items);
        $this->assertEquals(['fork', 'knife', 'spoon'], $items);

        $types = DB::table('items')->distinct('type')->get()->toArray();
        sort($types);
        $this->assertCount(2, $types);
        $this->assertEquals(['round', 'sharp'], $types);
    }

    public function testCustomId()
    {
        DB::table('items')->insert([
            ['_id' => 'knife', 'type' => 'sharp', 'amount' => 34],
            ['_id' => 'fork', 'type' => 'sharp', 'amount' => 20],
            ['_id' => 'spoon', 'type' => 'round', 'amount' => 3],
        ]);

        $item = DB::table('items')->find('knife');
        $this->assertEquals('knife', $item['_id']);

        $item = DB::table('items')->where('_id', 'fork')->first();
        $this->assertEquals('fork', $item['_id']);

        DB::table('users')->insert([
            ['_id' => 1, 'name' => 'Jane Doe'],
            ['_id' => 2, 'name' => 'John Doe'],
        ]);

        $item = DB::table('users')->find(1);
        $this->assertEquals(1, $item['_id']);
    }

    public function testTake()
    {
        DB::table('items')->insert([
            ['name' => 'knife', 'type' => 'sharp', 'amount' => 34],
            ['name' => 'fork', 'type' => 'sharp', 'amount' => 20],
            ['name' => 'spoon', 'type' => 'round', 'amount' => 3],
            ['name' => 'spoon', 'type' => 'round', 'amount' => 14],
        ]);

        $items = DB::table('items')->orderBy('name')->take(2)->get();
        $this->assertCount(2, $items);
        $this->assertEquals('fork', $items[0]['name']);
    }

    public function testSkip()
    {
        DB::table('items')->insert([
            ['name' => 'knife', 'type' => 'sharp', 'amount' => 34],
            ['name' => 'fork', 'type' => 'sharp', 'amount' => 20],
            ['name' => 'spoon', 'type' => 'round', 'amount' => 3],
            ['name' => 'spoon', 'type' => 'round', 'amount' => 14],
        ]);

        $items = DB::table('items')->orderBy('name')->skip(2)->get();
        $this->assertCount(2, $items);
        $this->assertEquals('spoon', $items[0]['name']);
    }

    public function testPluck()
    {
        DB::table('users')->insert([
            ['name' => 'Jane Doe', 'age' => 20],
            ['name' => 'John Doe', 'age' => 25],
        ]);

        $age = DB::table('users')->where('name', 'John Doe')->pluck('age')->toArray();
        $this->assertEquals([25], $age);
    }

    public function testList()
    {
        DB::table('items')->insert([
            ['name' => 'knife', 'type' => 'sharp', 'amount' => 34],
            ['name' => 'fork', 'type' => 'sharp', 'amount' => 20],
            ['name' => 'spoon', 'type' => 'round', 'amount' => 3],
            ['name' => 'spoon', 'type' => 'round', 'amount' => 14],
        ]);

        $list = DB::table('items')->pluck('name')->toArray();
        sort($list);
        $this->assertCount(4, $list);
        $this->assertEquals(['fork', 'knife', 'spoon', 'spoon'], $list);

        $list = DB::table('items')->pluck('type', 'name')->toArray();
        $this->assertCount(3, $list);
        $this->assertEquals(['knife' => 'sharp', 'fork' => 'sharp', 'spoon' => 'round'], $list);

        $list = DB::table('items')->pluck('name', '_id')->toArray();
        $this->assertCount(4, $list);
        $this->assertEquals(24, strlen(key($list)));
    }

    public function testAggregate()
    {
        DB::table('items')->insert([
            ['name' => 'knife', 'type' => 'sharp', 'amount' => 34],
            ['name' => 'fork', 'type' => 'sharp', 'amount' => 20],
            ['name' => 'spoon', 'type' => 'round', 'amount' => 3],
            ['name' => 'spoon', 'type' => 'round', 'amount' => 14],
        ]);

        $this->assertEquals(71, DB::table('items')->sum('amount'));
        $this->assertEquals(4, DB::table('items')->count('amount'));
        $this->assertEquals(3, DB::table('items')->min('amount'));
        $this->assertEquals(34, DB::table('items')->max('amount'));
        $this->assertEquals(17.75, DB::table('items')->avg('amount'));

        $this->assertEquals(2, DB::table('items')->where('name', 'spoon')->count('amount'));
        $this->assertEquals(14, DB::table('items')->where('name', 'spoon')->max('amount'));
    }

    public function testSubdocumentAggregate()
    {
        DB::table('items')->insert([
            ['name' => 'knife', 'amount' => ['hidden' => 10, 'found' => 3]],
            ['name' => 'fork', 'amount' => ['hidden' => 35, 'found' => 12]],
            ['name' => 'spoon', 'amount' => ['hidden' => 14, 'found' => 21]],
            ['name' => 'spoon', 'amount' => ['hidden' => 6, 'found' => 4]],
        ]);

        $this->assertEquals(65, DB::table('items')->sum('amount.hidden'));
        $this->assertEquals(4, DB::table('items')->count('amount.hidden'));
        $this->assertEquals(6, DB::table('items')->min('amount.hidden'));
        $this->assertEquals(35, DB::table('items')->max('amount.hidden'));
        $this->assertEquals(16.25, DB::table('items')->avg('amount.hidden'));
    }

    public function testSubdocumentArrayAggregate()
    {
        DB::table('items')->insert([
            ['name' => 'knife', 'amount' => [['hidden' => 10, 'found' => 3], ['hidden' => 5, 'found' => 2]]],
            [
                'name' => 'fork',
                'amount' => [
                    ['hidden' => 35, 'found' => 12],
                    ['hidden' => 7, 'found' => 17],
                    ['hidden' => 1, 'found' => 19],
                ],
            ],
            ['name' => 'spoon', 'amount' => [['hidden' => 14, 'found' => 21]]],
            ['name' => 'teaspoon', 'amount' => []],
        ]);

        $this->assertEquals(72, DB::table('items')->sum('amount.*.hidden'));
        $this->assertEquals(6, DB::table('items')->count('amount.*.hidden'));
        $this->assertEquals(1, DB::table('items')->min('amount.*.hidden'));
        $this->assertEquals(35, DB::table('items')->max('amount.*.hidden'));
        $this->assertEquals(12, DB::table('items')->avg('amount.*.hidden'));
    }

    public function testUpdateWithUpsert()
    {
        DB::table('items')->where('name', 'knife')
            ->update(
                ['amount' => 1],
                ['upsert' => true],
            );

        $this->assertEquals(1, DB::table('items')->count());

        Item::where('name', 'spoon')
            ->update(
                ['amount' => 1],
                ['upsert' => true],
            );

        $this->assertEquals(2, DB::table('items')->count());
    }

    public function testUpsert()
    {
        /** @see DatabaseQueryBuilderTest::testUpsertMethod() */
        // Insert 2 documents
        $result = DB::table('users')->upsert([
            ['email' => 'foo', 'name' => 'bar'],
            ['name' => 'bar2', 'email' => 'foo2'],
        ], 'email', 'name');

        $this->assertSame(2, $result);
        $this->assertSame(2, DB::table('users')->count());
        $this->assertSame('bar', DB::table('users')->where('email', 'foo')->first()['name']);

        // Update 1 document
        $result = DB::table('users')->upsert([
            ['email' => 'foo', 'name' => 'bar2'],
            ['name' => 'bar2', 'email' => 'foo2'],
        ], 'email', 'name');

        $this->assertSame(1, $result);
        $this->assertSame(2, DB::table('users')->count());
        $this->assertSame('bar2', DB::table('users')->where('email', 'foo')->first()['name']);

        // If no update fields are specified, all fields are updated
        $result = DB::table('users')->upsert([
            ['email' => 'foo', 'name' => 'bar3'],
        ], 'email');

        $this->assertSame(1, $result);
        $this->assertSame(2, DB::table('users')->count());
        $this->assertSame('bar3', DB::table('users')->where('email', 'foo')->first()['name']);
    }

    public function testUnset()
    {
        $id1 = DB::table('users')->insertGetId(['name' => 'John Doe', 'note1' => 'ABC', 'note2' => 'DEF']);
        $id2 = DB::table('users')->insertGetId(['name' => 'Jane Doe', 'note1' => 'ABC', 'note2' => 'DEF']);

        DB::table('users')->where('name', 'John Doe')->unset('note1');

        $user1 = DB::table('users')->find($id1);
        $user2 = DB::table('users')->find($id2);

        $this->assertArrayNotHasKey('note1', $user1);
        $this->assertArrayHasKey('note2', $user1);
        $this->assertArrayHasKey('note1', $user2);
        $this->assertArrayHasKey('note2', $user2);

        DB::table('users')->where('name', 'Jane Doe')->unset(['note1', 'note2']);

        $user2 = DB::table('users')->find($id2);
        $this->assertArrayNotHasKey('note1', $user2);
        $this->assertArrayNotHasKey('note2', $user2);
    }

    public function testUpdateSubdocument()
    {
        $id = DB::table('users')->insertGetId(['name' => 'John Doe', 'address' => ['country' => 'Belgium']]);

        DB::table('users')->where('_id', $id)->update(['address.country' => 'England']);

        $check = DB::table('users')->find($id);
        $this->assertEquals('England', $check['address']['country']);
    }

    public function testDates()
    {
        DB::table('users')->insert([
            ['name' => 'John Doe', 'birthday' => new UTCDateTime(Date::parse('1980-01-01 00:00:00'))],
            ['name' => 'Robert Roe', 'birthday' => new UTCDateTime(Date::parse('1982-01-01 00:00:00'))],
            ['name' => 'Mark Moe', 'birthday' => new UTCDateTime(Date::parse('1983-01-01 00:00:00.1'))],
            ['name' => 'Frank White', 'birthday' => new UTCDateTime(Date::parse('1960-01-01 12:12:12.1'))],
        ]);

        $user = DB::table('users')
            ->where('birthday', new UTCDateTime(Date::parse('1980-01-01 00:00:00')))
            ->first();
        $this->assertEquals('John Doe', $user['name']);

        $user = DB::table('users')
            ->where('birthday', new UTCDateTime(Date::parse('1960-01-01 12:12:12.1')))
            ->first();
        $this->assertEquals('Frank White', $user['name']);

        $user = DB::table('users')->where('birthday', '=', new DateTime('1980-01-01 00:00:00'))->first();
        $this->assertEquals('John Doe', $user['name']);

        $start = new UTCDateTime(1000 * strtotime('1950-01-01 00:00:00'));
        $stop  = new UTCDateTime(1000 * strtotime('1981-01-01 00:00:00'));

        $users = DB::table('users')->whereBetween('birthday', [$start, $stop])->get();
        $this->assertCount(2, $users);
    }

    public function testImmutableDates()
    {
        DB::table('users')->insert([
            ['name' => 'John Doe', 'birthday' => new UTCDateTime(Date::parse('1980-01-01 00:00:00'))],
            ['name' => 'Robert Roe', 'birthday' => new UTCDateTime(Date::parse('1982-01-01 00:00:00'))],
        ]);

        $users = DB::table('users')->where('birthday', '=', new DateTimeImmutable('1980-01-01 00:00:00'))->get();
        $this->assertCount(1, $users);

        $users = DB::table('users')->where('birthday', new DateTimeImmutable('1980-01-01 00:00:00'))->get();
        $this->assertCount(1, $users);

        $users = DB::table('users')->whereIn('birthday', [
            new DateTimeImmutable('1980-01-01 00:00:00'),
            new DateTimeImmutable('1982-01-01 00:00:00'),
        ])->get();
        $this->assertCount(2, $users);

        $users = DB::table('users')->whereBetween('birthday', [
            new DateTimeImmutable('1979-01-01 00:00:00'),
            new DateTimeImmutable('1983-01-01 00:00:00'),
        ])->get();

        $this->assertCount(2, $users);
    }

    public function testOperators()
    {
        DB::table('users')->insert([
            ['name' => 'John Doe', 'age' => 30],
            ['name' => 'Jane Doe'],
            ['name' => 'Robert Roe', 'age' => 'thirty-one'],
        ]);

        $results = DB::table('users')->where('age', 'exists', true)->get();
        $this->assertCount(2, $results);
        $resultsNames = [$results[0]['name'], $results[1]['name']];
        $this->assertContains('John Doe', $resultsNames);
        $this->assertContains('Robert Roe', $resultsNames);

        $results = DB::table('users')->where('age', 'exists', false)->get();
        $this->assertCount(1, $results);
        $this->assertEquals('Jane Doe', $results[0]['name']);

        $results = DB::table('users')->where('age', 'type', 2)->get();
        $this->assertCount(1, $results);
        $this->assertEquals('Robert Roe', $results[0]['name']);

        $results = DB::table('users')->where('age', 'mod', [15, 0])->get();
        $this->assertCount(1, $results);
        $this->assertEquals('John Doe', $results[0]['name']);

        $results = DB::table('users')->where('age', 'mod', [29, 1])->get();
        $this->assertCount(1, $results);
        $this->assertEquals('John Doe', $results[0]['name']);

        $results = DB::table('users')->where('age', 'mod', [14, 0])->get();
        $this->assertCount(0, $results);

        DB::table('items')->insert([
            ['name' => 'fork', 'tags' => ['sharp', 'pointy']],
            ['name' => 'spork', 'tags' => ['sharp', 'pointy', 'round', 'bowl']],
            ['name' => 'spoon', 'tags' => ['round', 'bowl']],
        ]);

        $results = DB::table('items')->where('tags', 'all', ['sharp', 'pointy'])->get();
        $this->assertCount(2, $results);

        $results = DB::table('items')->where('tags', 'all', ['sharp', 'round'])->get();
        $this->assertCount(1, $results);

        $results = DB::table('items')->where('tags', 'size', 2)->get();
        $this->assertCount(2, $results);

        $results = DB::table('items')->where('tags', '$size', 2)->get();
        $this->assertCount(2, $results);

        $results = DB::table('items')->where('tags', 'size', 3)->get();
        $this->assertCount(0, $results);

        $results = DB::table('items')->where('tags', 'size', 4)->get();
        $this->assertCount(1, $results);

        $regex   = new Regex('.*doe', 'i');
        $results = DB::table('users')->where('name', 'regex', $regex)->get();
        $this->assertCount(2, $results);

        $regex   = new Regex('.*doe', 'i');
        $results = DB::table('users')->where('name', 'regexp', $regex)->get();
        $this->assertCount(2, $results);

        $results = DB::table('users')->where('name', 'REGEX', $regex)->get();
        $this->assertCount(2, $results);

        $results = DB::table('users')->where('name', 'regexp', '/.*doe/i')->get();
        $this->assertCount(2, $results);

        $results = DB::table('users')->where('name', 'not regexp', '/.*doe/i')->get();
        $this->assertCount(1, $results);

        DB::table('users')->insert([
            [
                'name' => 'John Doe',
                'addresses' => [
                    ['city' => 'Ghent'],
                    ['city' => 'Paris'],
                ],
            ],
            [
                'name' => 'Jane Doe',
                'addresses' => [
                    ['city' => 'Brussels'],
                    ['city' => 'Paris'],
                ],
            ],
        ]);

        $users = DB::table('users')->where('addresses', 'elemMatch', ['city' => 'Brussels'])->get();
        $this->assertCount(1, $users);
        $this->assertEquals('Jane Doe', $users[0]['name']);
    }

    public function testIncrement()
    {
        DB::table('users')->insert([
            ['name' => 'John Doe', 'age' => 30, 'note' => 'adult'],
            ['name' => 'Jane Doe', 'age' => 10, 'note' => 'minor'],
            ['name' => 'Robert Roe', 'age' => null],
            ['name' => 'Mark Moe'],
        ]);

        $user = DB::table('users')->where('name', 'John Doe')->first();
        $this->assertEquals(30, $user['age']);

        DB::table('users')->where('name', 'John Doe')->increment('age');
        $user = DB::table('users')->where('name', 'John Doe')->first();
        $this->assertEquals(31, $user['age']);

        DB::table('users')->where('name', 'John Doe')->decrement('age');
        $user = DB::table('users')->where('name', 'John Doe')->first();
        $this->assertEquals(30, $user['age']);

        DB::table('users')->where('name', 'John Doe')->increment('age', 5);
        $user = DB::table('users')->where('name', 'John Doe')->first();
        $this->assertEquals(35, $user['age']);

        DB::table('users')->where('name', 'John Doe')->decrement('age', 5);
        $user = DB::table('users')->where('name', 'John Doe')->first();
        $this->assertEquals(30, $user['age']);

        DB::table('users')->where('name', 'Jane Doe')->increment('age', 10, ['note' => 'adult']);
        $user = DB::table('users')->where('name', 'Jane Doe')->first();
        $this->assertEquals(20, $user['age']);
        $this->assertEquals('adult', $user['note']);

        DB::table('users')->where('name', 'John Doe')->decrement('age', 20, ['note' => 'minor']);
        $user = DB::table('users')->where('name', 'John Doe')->first();
        $this->assertEquals(10, $user['age']);
        $this->assertEquals('minor', $user['note']);

        DB::table('users')->increment('age');
        $user = DB::table('users')->where('name', 'John Doe')->first();
        $this->assertEquals(11, $user['age']);
        $user = DB::table('users')->where('name', 'Jane Doe')->first();
        $this->assertEquals(21, $user['age']);
        $user = DB::table('users')->where('name', 'Robert Roe')->first();
        $this->assertNull($user['age']);
        $user = DB::table('users')->where('name', 'Mark Moe')->first();
        $this->assertEquals(1, $user['age']);
    }

    public function testProjections()
    {
        DB::table('items')->insert([
            ['name' => 'fork', 'tags' => ['sharp', 'pointy']],
            ['name' => 'spork', 'tags' => ['sharp', 'pointy', 'round', 'bowl']],
            ['name' => 'spoon', 'tags' => ['round', 'bowl']],
        ]);

        $results = DB::table('items')->project(['tags' => ['$slice' => 1]])->get();

        foreach ($results as $result) {
            $this->assertEquals(1, count($result['tags']));
        }
    }

    public function testValue()
    {
        DB::table('books')->insert([
            ['title' => 'Moby-Dick', 'author' => ['first_name' => 'Herman', 'last_name' => 'Melville']],
        ]);

        $this->assertEquals('Moby-Dick', DB::table('books')->value('title'));
        $this->assertEquals(['first_name' => 'Herman', 'last_name' => 'Melville'], DB::table('books')
            ->value('author'));
        $this->assertEquals('Herman', DB::table('books')->value('author.first_name'));
        $this->assertEquals('Melville', DB::table('books')->value('author.last_name'));
    }

    public function testHintOptions()
    {
        DB::table('items')->insert([
            ['name' => 'fork', 'tags' => ['sharp', 'pointy']],
            ['name' => 'spork', 'tags' => ['sharp', 'pointy', 'round', 'bowl']],
            ['name' => 'spoon', 'tags' => ['round', 'bowl']],
        ]);

        $results = DB::table('items')->hint(['$natural' => -1])->get();

        $this->assertEquals('spoon', $results[0]['name']);
        $this->assertEquals('spork', $results[1]['name']);
        $this->assertEquals('fork', $results[2]['name']);

        $results = DB::table('items')->hint(['$natural' => 1])->get();

        $this->assertEquals('spoon', $results[2]['name']);
        $this->assertEquals('spork', $results[1]['name']);
        $this->assertEquals('fork', $results[0]['name']);
    }

    public function testCursor()
    {
        $data = [
            ['name' => 'fork', 'tags' => ['sharp', 'pointy']],
            ['name' => 'spork', 'tags' => ['sharp', 'pointy', 'round', 'bowl']],
            ['name' => 'spoon', 'tags' => ['round', 'bowl']],
        ];
        DB::table('items')->insert($data);

        $results = DB::table('items')->orderBy('_id', 'asc')->cursor();

        $this->assertInstanceOf(LazyCollection::class, $results);
        foreach ($results as $i => $result) {
            $this->assertEquals($data[$i]['name'], $result['name']);
        }
    }

    public function testStringableColumn()
    {
        DB::table('users')->insert([
            ['name' => 'Jane Doe', 'age' => 36, 'birthday' => new UTCDateTime(new DateTime('1987-01-01 00:00:00'))],
            ['name' => 'John Doe', 'age' => 28, 'birthday' => new UTCDateTime(new DateTime('1995-01-01 00:00:00'))],
        ]);

        $nameColumn = Str::of('name');
        $this->assertInstanceOf(Stringable::class, $nameColumn, 'Ensure we are testing the feature with a Stringable instance');

        $user = DB::table('users')->where($nameColumn, 'John Doe')->first();
        $this->assertEquals('John Doe', $user['name']);

        // Test this other document to be sure this is not a random success to data order
        $user = DB::table('users')->where($nameColumn, 'Jane Doe')->orderBy('natural')->first();
        $this->assertEquals('Jane Doe', $user['name']);

        // With an operator
        $user = DB::table('users')->where($nameColumn, '!=', 'Jane Doe')->first();
        $this->assertEquals('John Doe', $user['name']);

        // whereIn and whereNotIn
        $user = DB::table('users')->whereIn($nameColumn, ['John Doe'])->first();
        $this->assertEquals('John Doe', $user['name']);

        $user = DB::table('users')->whereNotIn($nameColumn, ['John Doe'])->first();
        $this->assertEquals('Jane Doe', $user['name']);

        $ageColumn = Str::of('age');
        // whereBetween and whereNotBetween
        $user = DB::table('users')->whereBetween($ageColumn, [30, 40])->first();
        $this->assertEquals('Jane Doe', $user['name']);

        // whereBetween and whereNotBetween
        $user = DB::table('users')->whereNotBetween($ageColumn, [30, 40])->first();
        $this->assertEquals('John Doe', $user['name']);

        $birthdayColumn = Str::of('birthday');
        // whereDate
        $user = DB::table('users')->whereDate($birthdayColumn, '1995-01-01')->first();
        $this->assertEquals('John Doe', $user['name']);

        $user = DB::table('users')->whereDate($birthdayColumn, '<', '1990-01-01')
            ->orderBy($birthdayColumn, 'desc')->first();
        $this->assertEquals('Jane Doe', $user['name']);

        $user = DB::table('users')->whereDate($birthdayColumn, '>', '1990-01-01')
            ->orderBy($birthdayColumn, 'asc')->first();
        $this->assertEquals('John Doe', $user['name']);

        $user = DB::table('users')->whereDate($birthdayColumn, '!=', '1987-01-01')->first();
        $this->assertEquals('John Doe', $user['name']);

        // increment
        DB::table('users')->where($ageColumn, 28)->increment($ageColumn, 1);
        $user = DB::table('users')->where($ageColumn, 29)->first();
        $this->assertEquals('John Doe', $user['name']);
    }

    public function testIncrementEach()
    {
        DB::table('users')->insert([
            ['name' => 'John Doe', 'age' => 30, 'note' => 5],
            ['name' => 'Jane Doe', 'age' => 10, 'note' => 6],
            ['name' => 'Robert Roe', 'age' => null],
        ]);

        DB::table('users')->incrementEach([
            'age' => 1,
            'note' => 2,
        ]);
        $user = DB::table('users')->where('name', 'John Doe')->first();
        $this->assertEquals(31, $user['age']);
        $this->assertEquals(7, $user['note']);

        $user = DB::table('users')->where('name', 'Jane Doe')->first();
        $this->assertEquals(11, $user['age']);
        $this->assertEquals(8, $user['note']);

        $user = DB::table('users')->where('name', 'Robert Roe')->first();
        $this->assertSame(1, $user['age']);
        $this->assertSame(2, $user['note']);

        DB::table('users')->where('name', 'Jane Doe')->incrementEach([
            'age' => 1,
            'note' => 2,
        ], ['extra' => 'foo']);

        $user = DB::table('users')->where('name', 'Jane Doe')->first();
        $this->assertEquals(12, $user['age']);
        $this->assertEquals(10, $user['note']);
        $this->assertEquals('foo', $user['extra']);

        $user = DB::table('users')->where('name', 'John Doe')->first();
        $this->assertEquals(31, $user['age']);
        $this->assertEquals(7, $user['note']);
        $this->assertArrayNotHasKey('extra', $user);

        DB::table('users')->decrementEach([
            'age' => 1,
            'note' => 2,
        ], ['extra' => 'foo']);

        $user = DB::table('users')->where('name', 'John Doe')->first();
        $this->assertEquals(30, $user['age']);
        $this->assertEquals(5, $user['note']);
        $this->assertEquals('foo', $user['extra']);
    }
}
