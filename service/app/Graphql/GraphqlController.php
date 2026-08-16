<?php
namespace App\Graphql;

use GraphQL\GraphQL;
use GraphQL\Validator\Rules\QueryDepth;
use GraphQL\Validator\Rules\QueryComplexity;
use Common\Helper\Response;

class GraphqlController
{
    public function handle($request)
    {
        if (!\Common\Feature\FeatureFlags::isEnabled('graphql_api')) {
            return json(Response::error(403, 'GraphQL API is disabled'));
        }
        return $this->execute($request, Schema::full());
    }

    public function publicHandle($request)
    {
        if (!\Common\Feature\FeatureFlags::isEnabled('graphql_api')) {
            return json(Response::error(403, 'GraphQL API is disabled'));
        }
        return $this->execute($request, Schema::publicSchema());
    }

    private function execute($request, \GraphQL\Type\Schema $schema): \Webman\Http\Response
    {
        $input = json_decode($request->rawBody() ?: '{}', true);
        $query     = $input['query'] ?? null;
        $variables = $input['variables'] ?? null;

        if (!$query) {
            return json(['errors' => [['message' => 'Query is required']]]);
        }

        $context = ['userId' => $request->userId ?? null];

        try {
            $result = GraphQL::executeQuery(
                $schema,
                $query,
                null,
                $context,
                $variables,
                null,
                null,
                [new QueryDepth(5), new QueryComplexity(100)]
            );

            $output = $result->toArray();
        } catch (\Throwable $e) {
            $output = ['errors' => [['message' => $e->getMessage()]]];
        }

        return json($output);
    }
}
