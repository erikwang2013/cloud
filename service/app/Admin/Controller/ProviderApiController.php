<?php
namespace App\Admin\Controller;

use App\Provisioning\Model\ProviderApi;
use Common\Helper\Response;

class ProviderApiController
{
    public function index()
    {
        $providers = ProviderApi::orderBy('code')->get();
        return json(Response::success($providers));
    }

    public function store($request)
    {
        $data = $request->only(['name', 'code', 'api_key_encrypted', 'api_secret_encrypted', 'webhook_secret']);
        $data['status'] = 'active';
        $provider = ProviderApi::create($data);
        return json(Response::success($provider));
    }

    public function update($request, int $id)
    {
        $provider = ProviderApi::findOrFail($id);
        $provider->update($request->only(['name', 'api_key_encrypted', 'api_secret_encrypted', 'webhook_secret', 'status']));
        return json(Response::success($provider));
    }

    public function destroy(int $id)
    {
        ProviderApi::findOrFail($id)->update(['status' => 'disabled']);
        return json(Response::success());
    }
}
