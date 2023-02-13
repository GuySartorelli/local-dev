<?php

namespace App\DynamoDB;

use Aws\DynamoDb\DynamoDbClient;
use DynamoDbService;
use InvalidArgumentException;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;

if (class_exists(DynamoDbClient::class)) {
    class PrintTableTask extends BuildTask
    {
        private static $segment = 'dynamodb-print-table';

        protected $title = 'Print DynamoDB table';

        protected $description = 'Print the contents of a table in the connected DynamoDB docker container';

        public function run($request)
        {
            $tableName = $request->getVar('table') ?? Environment::getEnv('AWS_DYNAMODB_SESSION_TABLE');
            if (!$tableName) {
                throw new InvalidArgumentException('A table name must be passed in or set as AWS_DYNAMODB_SESSION_TABLE');
            }

            $client = DynamoDbService::getClient($request);
            $response = $client->scan([
                'TableName' => $tableName,
                'KeySchema' => [['AttributeName' => 'id', 'KeyType' => 'HASH']],
            ]);

            var_dump($response->toArray()['Items']);
        }
    }
}
