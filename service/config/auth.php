<?php
return [
    'jwt' => [
        'algorithm'   => 'RS256',
        'private_key' => getenv('JWT_PRIVATE_KEY'),
        'public_key'  => getenv('JWT_PUBLIC_KEY'),
        'access_ttl'  => 7200,
        'refresh_ttl' => 2592000,
        'issuer'      => 'cloud-platform',
    ],
    'password' => [
        'algo'  => PASSWORD_BCRYPT,
        'cost'  => 12,
        'min_length' => 8,
    ],
    'mfa' => [
        'issuer' => 'CloudPlatform',
        'digits' => 6,
        'period' => 30,
        'algo'   => 'sha1',
    ],
];
