<?php
namespace App\Controller;

use Common\Helper\Response;

class UploadController
{
    private const ALLOWED_TYPES = ['avatar', 'kyc', 'attach'];
    private const ALLOWED_EXT   = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
    private const MIME_MAP      = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'pdf'  => ['application/pdf'],
    ];
    private const MAX_SIZES   = [
        'avatar' => 2 * 1024 * 1024,
        'kyc'    => 5 * 1024 * 1024,
        'attach' => 10 * 1024 * 1024,
    ];

    public function upload($request)
    {
        $file = $request->file('file');
        if (!$file) {
            return json(Response::error(422, 'No file uploaded'));
        }

        $ext  = strtolower(pathinfo($file->getUploadName(), PATHINFO_EXTENSION));
        $type = $request->input('type', 'attach');
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return json(Response::error(422, 'Invalid upload type'));
        }
        $maxSize = self::MAX_SIZES[$type];

        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return json(Response::error(422, 'File type not allowed: ' . $ext));
        }
        if ($file->getSize() > $maxSize) {
            return json(Response::error(422, 'File too large. Max: ' . ($maxSize / 1024 / 1024) . 'MB'));
        }

        // MIME 内容嗅探：防 polyglot 文件（伪造扩展名）
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer((string) file_get_contents($file->getRealPath()));
        if (!in_array($mime, self::MIME_MAP[$ext] ?? [], true)) {
            return json(Response::error(422, 'File content does not match its extension'));
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = storage_path("uploads/{$type}/" . date('Y/m/d'));
        if (!is_dir($destPath)) {
            mkdir($destPath, 0755, true);
        }

        $file->move($destPath . '/' . $filename);

        $url = "/storage/uploads/{$type}/" . date('Y/m/d') . '/' . $filename;
        return json(Response::success(['url' => $url, 'filename' => $filename, 'size' => $file->getSize()]));
    }
}
