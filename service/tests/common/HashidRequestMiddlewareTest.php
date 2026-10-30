<?php

namespace Tests\common;

use Common\hashid\HashidService;
use Common\hashid\middleware\HashidRequestMiddleware;
use Erikwang2013\Hashids\HashidsFactory;
use Erikwang2013\Hashids\HashidsManager;
use PHPUnit\Framework\TestCase;
use Tests\support\RequestMock;

final class HashidRequestMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $config = [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'salt' => getenv('HASHIDS_SALT') ?: 'test-salt',
                    'length' => (int)(getenv('HASHIDS_LENGTH') ?: 12),
                    'alphabet' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
                ],
            ],
        ];

        $prop = (new \ReflectionClass(HashidService::class))->getProperty('manager');
        $prop->setValue(null, new HashidsManager($config, new HashidsFactory()));
    }

    public function testDecodedInputsReachInputAndOnly(): void
    {
        $id = HashidService::encode(11);
        $cartIds = [HashidService::encode(21), HashidService::encode(22)];
        $channelId = HashidService::encode(31);

        $request = new RequestMock(['id' => $id, 'page' => '2'], [
            'cart_ids' => $cartIds,
            'channel_id' => $channelId,
            'note' => 'keep-me',
        ]);

        $seen = [];
        $result = (new HashidRequestMiddleware())->process($request, function (RequestMock $req) use (&$seen) {
            $seen = [
                'id' => $req->input('id'),
                'cart_ids' => $req->input('cart_ids'),
                'channel_id' => $req->input('channel_id'),
                'note' => $req->input('note'),
                'only' => $req->only(['cart_ids']),
                'raw' => $req->rawInputs,
            ];
            return 'next';
        });

        $this->assertSame('next', $result);
        $this->assertSame(11, $seen['id']);
        $this->assertSame([21, 22], $seen['cart_ids']);
        $this->assertSame(31, $seen['channel_id']);
        $this->assertSame('keep-me', $seen['note']);
        $this->assertSame(['cart_ids' => [21, 22]], $seen['only']);
        $this->assertSame([
            'id' => $id,
            'page' => '2',
            'cart_ids' => $cartIds,
            'channel_id' => $channelId,
            'note' => 'keep-me',
        ], $seen['raw']);
    }

    public function testInvalidHashidIsLeftUnchanged(): void
    {
        $request = new RequestMock(['id' => 'not-a-hashid'], []);

        (new HashidRequestMiddleware())->process($request, fn () => null);

        $this->assertSame('not-a-hashid', $request->input('id'));
    }

    public function testDecodeRequestCanRunAgainAfterDecryptedFieldsAreMerged(): void
    {
        $hash = HashidService::encode(5);
        $request = new RequestMock([], []);

        (new HashidRequestMiddleware())->process($request, fn () => null);
        // 模拟 EncryptionMiddleware 解密后把原始 hashid 合并进 post
        $request->setPost('order_id', $hash);
        HashidRequestMiddleware::decodeRequest($request);

        $this->assertSame(5, $request->input('order_id'));
    }
}
