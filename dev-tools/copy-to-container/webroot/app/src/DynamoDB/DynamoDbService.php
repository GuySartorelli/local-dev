<?php

use Aws\Credentials\CredentialProvider;
use Aws\DynamoDb\DynamoDbClient;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;

if (!class_exists(DynamoDbClient::class)) {
    return;
}

final class DynamoDbService
{
    public static function getClient(?HTTPRequest $request = null): DynamoDbClient
    {
        return new DynamoDbClient([
            'version' => '2012-08-10',
            'endpoint' => static::getEndpoint($request),
            'region' => self::getRegion($request),
            'credentials' => self::getCredentials(),
        ]);
    }

    public static function getRegion(?HTTPRequest $request = null): string
    {
        $region = $request ? $request->getVar('region') : null;
        return $region ?? Environment::getEnv('AWS_REGION_NAME') ?? 'ap-southeast-2';
    }

    public static function getEndpoint(?HTTPRequest $request = null): string
    {
        $endpoint = $request ? $request->getVar('endpoint') : null;
        return $endpoint ?? Environment::getEnv('AWS_DYNAMODB_ENDPOINT') ?? 'http://dynamodb-local:8000';
    }

    public static function getCredentials(): array|Closure
    {
        if (!empty($awsAccessKey) && !empty($awsSecretKey)) {
            return [
                'key' => $awsAccessKey,
                'secret' => $awsSecretKey,
            ];
        } else {
            return CredentialProvider::defaultProvider();
        }
    }
}
