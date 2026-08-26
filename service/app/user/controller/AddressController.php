<?php
namespace App\user\controller;

use App\user\model\UserAddress;
use Common\helper\Response;

class AddressController
{
    public function index($request)
    {
        $addresses = UserAddress::where('user_id', $request->userId)
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return json(Response::success($addresses));
    }

    public function store($request)
    {
        $data = $request->only(['type', 'name', 'phone', 'country', 'state', 'city', 'address', 'postcode']);
        $data['user_id'] = $request->userId;

        if ($request->input('is_default', false)) {
            UserAddress::where('user_id', $request->userId)->update(['is_default' => false]);
            $data['is_default'] = true;
        }

        $addr = UserAddress::create($data);
        return json(Response::success($addr));
    }

    public function update($request, int $id)
    {
        $addr = UserAddress::where('id', $id)->where('user_id', $request->userId)->firstOrFail();
        $data = $request->only(['type', 'name', 'phone', 'country', 'state', 'city', 'address', 'postcode']);

        if ($request->input('is_default', false)) {
            UserAddress::where('user_id', $request->userId)->update(['is_default' => false]);
            $data['is_default'] = true;
        }

        $addr->update($data);
        return json(Response::success($addr));
    }

    public function destroy($request, int $id)
    {
        UserAddress::where('id', $id)->where('user_id', $request->userId)->delete();
        return json(Response::success());
    }
}
