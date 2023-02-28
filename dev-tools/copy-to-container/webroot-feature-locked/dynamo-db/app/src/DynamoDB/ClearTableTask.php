<?php

namespace App\DynamoDB;

use Aws\DynamoDb\DynamoDbClient;
use DynamoDbService;
use InvalidArgumentException;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;

class ClearTableTask extends BuildTask
{
    private static $segment = 'dynamodb-clear-table';

    protected $title = 'Clear DynamoDB table';

    protected $description = 'Clear a table in the connected DynamoDB docker container';

    public function run($request)
    {
        $tableName = $request->getVar('table') ?? Environment::getEnv('AWS_DYNAMODB_SESSION_TABLE');
        if (!$tableName) {
            throw new InvalidArgumentException('A table name must be passed in or set as AWS_DYNAMODB_SESSION_TABLE');
        }
        $client = DynamoDbService::getClient($request);

        foreach ($this->getItemsInTable($client, $tableName) as $item) {
            $client->deleteItem([
                'TableName' => $tableName,
                'Key' => ['id' => $item['id']]
            ]);
        }

        echo 'Done';
    }

    private function getItemsInTable(DynamoDbClient $client, string $tableName): array
    {
        return $client->scan([
            'TableName' => $tableName,
            'KeySchema' => [['AttributeName' => 'id', 'KeyType' => 'HASH']],
        ])['Items'];
    }
}
