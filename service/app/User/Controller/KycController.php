<?php
namespace App\User\Controller;

use App\User\Model\UserKyc;
use Common\Helper\Response;

class KycController
{
    public function submit($request)
    {
        $existing = UserKyc::where('user_id', $request->userId)
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($existing) {
            return json(Response::error(422, 'KYC already submitted or approved'));
        }

        $kyc = UserKyc::create([
            'user_id'      => $request->userId,
            'doc_type'     => $request->input('doc_type'),
            'doc_number'   => $request->input('doc_number'),
            'doc_front'    => $request->input('doc_front'),
            'doc_back'     => $request->input('doc_back'),
            'full_name'    => $request->input('full_name'),
            'birth_date'   => $request->input('birth_date'),
            'nationality'  => $request->input('nationality'),
            'status'       => 'pending',
        ]);

        return json(Response::success($kyc, 'KYC submitted for review'));
    }
}
