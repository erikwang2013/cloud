<?php
namespace Common\helper;

class Validator
{
    public static function required(array $data, array $fields): ?string
    {
        foreach ($fields as $field) {
            if (empty($data[$field])) {
                return $field;
            }
        }
        return null;
    }

    public static function email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function minLength(string $value, int $min): bool
    {
        return mb_strlen($value) >= $min;
    }
}
