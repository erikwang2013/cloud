<?php
namespace App\provisioning\controller;

use App\provisioning\model\Resource;
use App\provisioning\service\ProviderFactory;
use Common\helper\Response;
use Common\webhook\WebhookDispatcher;

class ResourceController
{
    public function myResources($request)
    {
        $resources = Resource::where('user_id', $request->userId)
            ->with('product')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return json(Response::success($resources));
    }

    public function show($request, int $id)
    {
        $resource = Resource::where('user_id', $request->userId)
            ->with(['product', 'disks'])
            ->findOrFail($id);

        return json(Response::success($resource));
    }

    public function status($request, int $id)
    {
        $resource = Resource::where('user_id', $request->userId)->findOrFail($id);

        $factory = new ProviderFactory();
        $provider = $factory->createFromResource($resource);
        $status = $provider->status($resource);

        return json(Response::success($status));
    }

    public function consoleUrl($request, int $id)
    {
        $resource = Resource::where('user_id', $request->userId)->findOrFail($id);

        $factory = new ProviderFactory();
        $provider = $factory->createFromResource($resource);
        $url = $provider->consoleUrl($resource);

        return json(Response::success(['url' => $url]));
    }

    public function upgrade($request, int $id)
    {
        $resource = Resource::findOrFail($id);
        $newSpecs = $request->only(['cpu', 'ram']);

        $factory = new ProviderFactory();
        $provider = $factory->createFromResource($resource);
        $result = $provider->upgrade($resource, $newSpecs);

        if ($result->status === 'success') {
            return json(Response::success());
        }
        return json(Response::error(500, $result->errorMessage));
    }

    public function destroy($request, int $id)
    {
        $resource = Resource::findOrFail($id);

        $factory = new ProviderFactory();
        $provider = $factory->createFromResource($resource);
        $result = $provider->destroy($resource);

        if ($result->status === 'success') {
            $resource->update(['status' => 'destroyed']);

            WebhookDispatcher::dispatch(WebhookDispatcher::EVENT_RESOURCE_DESTROYED, [
                'resource_id' => $resource->id,
                'type'        => $resource->type,
            ]);

            return json(Response::success());
        }
        return json(Response::error(500, $result->errorMessage));
    }
}
