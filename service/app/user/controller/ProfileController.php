<?php
namespace App\user\controller;

use App\user\model\User;
use App\user\model\UserProfile;
use Common\helper\Response;

class ProfileController
{
    public function show($request)
    {
        $user = User::with(['profile', 'kyc', 'balances', 'addresses'])->findOrFail($request->userId);
        return json(Response::success($user));
    }

    public function update($request)
    {
        $user = User::findOrFail($request->userId);
        $profile = UserProfile::where('user_id', $request->userId)->firstOrFail();

        $data = $request->only(['phone', 'language', 'currency', 'timezone']);
        if ($data) {
            $user->update($data);
        }

        $profileData = $request->only(['first_name', 'last_name', 'company', 'address', 'city', 'state', 'postal_code', 'country', 'tax_id']);
        if ($profileData) {
            $profile->update($profileData);
        }

        return json(Response::success($user->load('profile')));
    }
}
