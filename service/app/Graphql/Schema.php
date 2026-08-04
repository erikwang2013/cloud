<?php
namespace App\Graphql;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema as GraphQLSchema;

class Schema
{
    private static ?GraphQLSchema $schema = null;
    private static ?GraphQLSchema $publicSchema = null;

    public static function full(): GraphQLSchema
    {
        if (self::$schema) return self::$schema;

        $productType = new ObjectType([
            'name'   => 'Product',
            'fields' => [
                'id'          => Type::id(),
                'name'        => Type::string(),
                'description' => Type::string(),
                'category_id' => Type::int(),
                'status'      => Type::string(),
                'min_price'   => Type::float(),
                'created_at'  => Type::string(),
            ],
        ]);

        $resourceType = new ObjectType([
            'name'   => 'Resource',
            'fields' => [
                'id'         => Type::id(),
                'user_id'    => Type::int(),
                'type'       => Type::string(),
                'status'     => Type::string(),
                'expired_at' => Type::string(),
                'created_at' => Type::string(),
            ],
        ]);

        $orderType = new ObjectType([
            'name'   => 'Order',
            'fields' => [
                'id'         => Type::id(),
                'order_no'   => Type::string(),
                'user_id'    => Type::int(),
                'status'     => Type::string(),
                'total'      => Type::float(),
                'currency'   => Type::string(),
                'paid_at'    => Type::string(),
                'created_at' => Type::string(),
            ],
        ]);

        $queryType = new ObjectType([
            'name'   => 'Query',
            'fields' => [
                'products' => [
                    'type'    => Type::listOf($productType),
                    'args'    => ['category_id' => Type::int(), 'search' => Type::string()],
                    'resolve' => function ($root, array $args) {
                        $q = \App\Product\Model\Product::query()->where('status', 'active');
                        if (!empty($args['category_id'])) $q->where('category_id', $args['category_id']);
                        if (!empty($args['search'])) {
                            $q->where(function ($sq) use ($args) {
                                $sq->where('name', 'like', "%{$args['search']}%")
                                   ->orWhere('description', 'like', "%{$args['search']}%");
                            });
                        }
                        return $q->limit(50)->get()->toArray();
                    },
                ],
                'myResources' => [
                    'type'    => Type::listOf($resourceType),
                    'resolve' => function ($root, array $args, $context) {
                        return \App\Provisioning\Model\Resource::where('user_id', $context['userId'])
                            ->orderBy('created_at', 'desc')->limit(50)->get()->toArray();
                    },
                ],
                'myOrders' => [
                    'type'    => Type::listOf($orderType),
                    'resolve' => function ($root, array $args, $context) {
                        return \App\Order\Model\Order::where('user_id', $context['userId'])
                            ->orderBy('created_at', 'desc')->limit(50)->get()->toArray();
                    },
                ],
            ],
        ]);

        self::$schema = new GraphQLSchema(['query' => $queryType]);
        return self::$schema;
    }

    public static function publicSchema(): GraphQLSchema
    {
        if (self::$publicSchema) return self::$publicSchema;

        $productType = new ObjectType([
            'name'   => 'Product',
            'fields' => [
                'id'         => Type::id(),
                'name'       => Type::string(),
                'category_id' => Type::int(),
                'status'     => Type::string(),
            ],
        ]);

        $queryType = new ObjectType([
            'name'   => 'Query',
            'fields' => [
                'products' => [
                    'type'    => Type::listOf($productType),
                    'args'    => ['category_id' => Type::int()],
                    'resolve' => function ($root, array $args) {
                        $q = \App\Product\Model\Product::query()->where('status', 'active');
                        if (!empty($args['category_id'])) $q->where('category_id', $args['category_id']);
                        return $q->limit(20)->get()->toArray();
                    },
                ],
            ],
        ]);

        self::$publicSchema = new GraphQLSchema(['query' => $queryType]);
        return self::$publicSchema;
    }
}
