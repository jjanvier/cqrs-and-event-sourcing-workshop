<?php

use EventSourcing\Aggregate\Repository\EventSourcedAggregateRepository;
use EventSourcing\EventStore\EventStore;
use EventSourcing\EventStore\Storage\FlywheelStorageFacility;
use EventSourcing\EventStore\StorageFacility;
use EventSourcing\Projection\EventDispatcher;
use Twitsup\Application\FollowUserHandler;
use Twitsup\Application\RegisterUserHandler;
use Twitsup\Application\SendTweetHandler;
use Twitsup\Domain\Model\Subscription\Subscription;
use Twitsup\Domain\Model\Subscription\UserFollowed;
use Twitsup\Domain\Model\Subscription\UserStartedFollowing;
use Twitsup\Domain\Model\Subscription\UserUnfollowed;
use Twitsup\Domain\Model\User\UserRegistered;
use Twitsup\ReadModel\FollowersProjector;
use Twitsup\ReadModel\FollowersRepository;
use Twitsup\ReadModel\SubscriptionLookupProjector;
use Twitsup\ReadModel\SubscriptionLookupRepository;
use Twitsup\ReadModel\UserLookupProjector;
use Twitsup\ReadModel\UserLookupRepository;
use Xtreamwayz\Pimple\Container;

$config = [
    'database_path' => realpath(__DIR__ . '/../var')
];

$container = new Container();

/*
 * Event store, event dispatching, etc.
 */
$container[StorageFacility::class] = function () use ($config) {
    return new FlywheelStorageFacility($config['database_path']);
};

$container[EventDispatcher::class] = function ($container) {
    $eventDispatcher = new EventDispatcher();

    $eventDispatcher->on(UserRegistered::class, $container[UserLookupProjector::class]);
    
    $eventDispatcher->on(UserStartedFollowing::class, $container[SubscriptionLookupProjector::class]);

    $followersProjector = $container[FollowersProjector::class];
    $eventDispatcher->on(UserStartedFollowing::class, [$followersProjector, 'onUserStartedFollowing']);
    $eventDispatcher->on(UserFollowed::class, [$followersProjector, 'onUserFollowed']);
    $eventDispatcher->on(UserUnfollowed::class, [$followersProjector, 'onUserUnfollowed']);

    return $eventDispatcher;
};

$container[EventStore::class] = function ($container) {
    return new EventStore(
        $container[StorageFacility::class],
        $container[EventDispatcher::class]
    );
};

/*
 * Domain model
 */
$container['Twitsup\Domain\Model\TweetRepository'] = function ($container) {
    return new EventSourcedAggregateRepository(
        $container[EventSourcing\EventStore\EventStore::class],
        \Twitsup\Domain\Model\Tweet\Tweet::class
    );
};
$container['Twitsup\Domain\Model\UserRepository'] = function ($container) {
    return new EventSourcedAggregateRepository(
        $container[EventSourcing\EventStore\EventStore::class],
        \Twitsup\Domain\Model\User\User::class
    );
};
$container['Twitsup\Domain\Model\SubscriptionRepository'] = function ($container) {
    return new EventSourcedAggregateRepository(
        $container[EventSourcing\EventStore\EventStore::class],
        Subscription::class
    );
};

/*
 * Read model
 */
$container[UserLookupRepository::class] = function () use ($config) {
    return new UserLookupRepository($config['database_path']);
};
$container[UserLookupProjector::class] = function ($container) {
    return new UserLookupProjector($container[UserLookupRepository::class]);
};

$container[SubscriptionLookupRepository::class] = function () use ($config) {
    return new SubscriptionLookupRepository($config['database_path']);
};
$container[SubscriptionLookupProjector::class] = function ($container) {
    return new SubscriptionLookupProjector($container[SubscriptionLookupRepository::class]);
};

$container[FollowersRepository::class] = function () {
    return new FollowersRepository();
};
$container[FollowersProjector::class] = function ($container) {
    return new FollowersProjector($container[FollowersRepository::class]);
};

/*
 * Application services
 */
$container[RegisterUserHandler::class] = function ($container) {
    return new RegisterUserHandler($container['Twitsup\Domain\Model\UserRepository']);
};
$container[SendTweetHandler::class] = function ($container) {
    return new SendTweetHandler($container['Twitsup\Domain\Model\TweetRepository']);
};
$container[FollowUserHandler::class] = function ($container) {
    return new FollowUserHandler(
        $container[UserLookupRepository::class],
        $container[SubscriptionLookupRepository::class],
        $container['Twitsup\Domain\Model\SubscriptionRepository']
    );
};

return $container;
