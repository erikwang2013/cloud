<?php
namespace App\Storage\Controller;

use App\Storage\Model\StorageBucket;
use App\Storage\Service\PresignedUrlService;
use Common\Helper\Response;

class StorageController
{
    public function index($request)
    {
        $userId = $request->userId;
        $buckets = StorageBucket::whereHas('resource', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->orderBy('created_at', 'desc')->get()->makeHidden([
            'access_key_encrypted', 'secret_key_encrypted',
        ]);

        return json(Response::success($buckets));
    }

    public function show($request, int $id)
    {
        $userId = $request->userId;
        $bucket = StorageBucket::whereHas('resource', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->findOrFail($id)->makeHidden([
            'access_key_encrypted', 'secret_key_encrypted',
        ]);

        return json(Response::success($bucket));
    }

    public function presignUpload($request, int $id)
    {
        $userId = $request->userId;
        $bucket = StorageBucket::whereHas('resource', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->findOrFail($id);

        $key         = $request->input('key');
        $contentType = $request->input('content_type', 'application/octet-stream');
        $expires     = min((int) $request->input('expires', 3600), 86400);

        if (!$key) {
            return json(Response::error('key is required'));
        }

        $service = new PresignedUrlService();
        $url = $service->generateUploadUrl($bucket, $key, $contentType, $expires);

        return json(Response::success(['upload_url' => $url, 'key' => $key, 'expires_in' => $expires]));
    }

    public function presignDownload($request, int $id)
    {
        $userId = $request->userId;
        $bucket = StorageBucket::whereHas('resource', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->findOrFail($id);

        $key     = $request->input('key');
        $expires = min((int) $request->input('expires', 3600), 86400);

        if (!$key) {
            return json(Response::error('key is required'));
        }

        $service = new PresignedUrlService();
        $url = $service->generateDownloadUrl($bucket, $key, $expires);

        return json(Response::success(['download_url' => $url, 'key' => $key, 'expires_in' => $expires]));
    }

    public function credentials($request, int $id)
    {
        $userId = $request->userId;
        $bucket = StorageBucket::whereHas('resource', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->findOrFail($id);

        return json(Response::success([
            'bucket_name'   => $bucket->bucket_name,
            'endpoint'       => $bucket->endpoint,
            'region'         => $bucket->region,
            'access_key'     => $bucket->access_key_encrypted,
            'secret_key'     => $bucket->secret_key_encrypted,
        ]));
    }
}
