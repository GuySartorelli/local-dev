<?php

namespace App\DynamoDB;

use Aws\DynamoDb\DynamoDbClient;
use DynamoDbService;
use InvalidArgumentException;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;

class CreateTableTask extends BuildTask
{
    private static $segment = 'dynamodb-create-table';

    protected $title = 'Create DynamoDB table';

    protected $description = 'Create a table in the connected DynamoDB docker container';

    public function run($request)
    {
        $tableName = $request->getVar('table') ?? Environment::getEnv('AWS_DYNAMODB_SESSION_TABLE');
        if (!$tableName) {
            throw new InvalidArgumentException('A table name must be passed in or set as AWS_DYNAMODB_SESSION_TABLE');
        }

        $client = DynamoDbService::getClient($request);
        $response = $client->createTable([
            'TableName' => $tableName,
            'KeySchema' => [['AttributeName' => 'id', 'KeyType' => 'HASH']],
            'AttributeDefinitions' => [['AttributeName' => 'id', 'AttributeType' => 'S']],
            'ProvisionedThroughput' => ['ReadCapacityUnits' => 1, 'WriteCapacityUnits' => 1],
        ]);

        var_dump($response->toArray()['TableDescription']);
    }
}
